/**
 * GSI Talk Guard · página de administración.
 * JavaScript sin dependencias. Todo dato que viene del servidor se inserta
 * con textContent (nunca innerHTML) para evitar inyección de código.
 */
(function () {
	'use strict'

	const raiz = document.getElementById('gsitg')
	if (!raiz) {
		return
	}
	const rutas = JSON.parse(raiz.dataset.rutas)
	const token = document.head.dataset.requesttoken || ''

	const NIVEL = { critica: 4, alta: 3, media: 2, baja: 1 }
	const SEVERIDAD = { critica: 'Crítica', alta: 'Alta', media: 'Media', baja: 'Baja' }
	const ACCION = {
		alerta: 'Alerta', enmascarado: 'Ocultado', configuracion: 'Configuración',
		leida: 'Evidencia leída', fallida: 'Lectura fallida',
	}
	const OTRAS_REGLAS = {
		archivo_compartido: 'Archivo compartido',
		cambio_configuracion: 'Cambio de configuración',
		acceso_evidencia: 'Consulta de evidencia',
	}
	const MODOS = {
		normal: ['Normal', 'Aplica la acción de cada regla: alerta y, si la regla lo indica, oculta el dato.'],
		solo_alertar: ['Solo alertar', 'Interruptor de emergencia: sigue alertando, pero nunca oculta datos.'],
		desactivado: ['Desactivado', 'No analiza ningún mensaje. Talk funciona sin protección.'],
	}

	// ------------------------------------------------------------------
	// Utilidades
	// ------------------------------------------------------------------

	function el(etiqueta, atributos, ...hijos) {
		const e = document.createElement(etiqueta)
		for (const [k, v] of Object.entries(atributos || {})) {
			if (v === null || v === undefined || v === false) {
				continue
			}
			if (k === 'class') {
				e.className = v
			} else if (k === 'text') {
				e.textContent = v
			} else if (k.startsWith('on') && typeof v === 'function') {
				e.addEventListener(k.slice(2), v)
			} else if (v === true) {
				e.setAttribute(k, '')
			} else {
				e.setAttribute(k, String(v))
			}
		}
		for (const h of hijos.flat(Infinity)) {
			if (h === null || h === undefined || h === false) {
				continue
			}
			e.append(h instanceof Node ? h : document.createTextNode(String(h)))
		}
		return e
	}

	async function api(nombre, { metodo = 'GET', datos = null, parametros = null } = {}) {
		let url = rutas[nombre]
		if (parametros) {
			url += '?' + new URLSearchParams(parametros).toString()
		}
		const cabeceras = { requesttoken: token, Accept: 'application/json' }
		if (datos) {
			cabeceras['Content-Type'] = 'application/json'
		}
		const r = await fetch(url, {
			method: metodo,
			headers: cabeceras,
			credentials: 'same-origin',
			body: datos ? JSON.stringify(datos) : undefined,
		})
		let cuerpo = null
		try {
			cuerpo = await r.json()
		} catch (e) {
			// respuesta sin JSON
		}
		if (r.status === 403 || r.status === 401) {
			throw new Error('No tiene permiso para esta sección. Pida a un administrador que se la delegue.')
		}
		if (!r.ok || (cuerpo && cuerpo.ok === false)) {
			throw new Error((cuerpo && (cuerpo.error || cuerpo.message)) || 'Error del servidor (' + r.status + ').')
		}
		return cuerpo
	}

	let temporizadorAviso = null
	function aviso(mensaje, tipo) {
		const caja = document.getElementById('gsitg-aviso')
		caja.textContent = mensaje
		caja.className = 'gsitg-aviso gsitg-aviso--' + (tipo || 'ok')
		caja.hidden = false
		clearTimeout(temporizadorAviso)
		temporizadorAviso = setTimeout(() => { caja.hidden = true }, tipo === 'error' ? 10000 : 5000)
	}

	function fecha(iso) {
		if (!iso) {
			return '—'
		}
		const d = new Date(iso)
		return isNaN(d) ? iso : d.toLocaleString('es-VE', { dateStyle: 'short', timeStyle: 'short' })
	}

	function insignia(severidad) {
		return el('span', { class: 'gsitg-sev gsitg-sev--' + severidad, text: SEVERIDAD[severidad] || severidad })
	}

	function nombreRegla(id) {
		if (OTRAS_REGLAS[id]) {
			return OTRAS_REGLAS[id]
		}
		const r = estado && estado.reglas.find((x) => x.id === id)
		return r ? r.nombre : id
	}

	function lineas(texto) {
		return texto.split(/[\n,;]+/).map((s) => s.trim()).filter(Boolean)
	}

	function seleccion(valor, opciones, atributos) {
		const s = el('select', atributos || {})
		for (const [v, etiqueta] of opciones) {
			const o = el('option', { value: v, text: etiqueta })
			if (v === valor) {
				o.selected = true
			}
			s.append(o)
		}
		return s
	}

	// ------------------------------------------------------------------
	// Estado y pestañas
	// ------------------------------------------------------------------

	let estado = null
	const panel = (nombre) => raiz.querySelector('[data-panel="' + nombre + '"]')

	async function cargarEstado() {
		estado = await api('estado')
	}

	function mostrarPestana(nombre) {
		for (const b of raiz.querySelectorAll('.gsitg-tab')) {
			b.setAttribute('aria-selected', b.dataset.tab === nombre ? 'true' : 'false')
		}
		for (const p of raiz.querySelectorAll('.gsitg-panel')) {
			p.hidden = p.dataset.panel !== nombre
		}
		if (nombre === 'alertas') {
			cargarAlertas()
		} else if (nombre === 'config') {
			cargarEstado().then(dibujarConfig).catch((e) => aviso(e.message, 'error'))
		} else if (nombre === 'probar' && !panel('probar').firstChild) {
			dibujarProbar()
		}
	}

	for (const b of raiz.querySelectorAll('.gsitg-tab')) {
		b.addEventListener('click', () => mostrarPestana(b.dataset.tab))
	}

	// ------------------------------------------------------------------
	// Pestaña Alertas
	// ------------------------------------------------------------------

	const filtros = { dias: '1', severidad: 'baja', regla: '', autor: '', accion: '', pagina: 1 }
	let activadorModal = null
	let temporizadorAuto = null

	function dibujarAlertas() {
		const p = panel('alertas')
		p.replaceChildren()
		const tituloModal = el('h2', { id: 'gsitg-modal-titulo', text: 'Detalle de alerta' })
		const cuerpoModal = el('div', { class: 'gsitg-modal__contenido' })
		const modal = el('dialog', { id: 'gsitg-alerta-modal', class: 'gsitg-modal', 'aria-labelledby': 'gsitg-modal-titulo' },
			el('header', { class: 'gsitg-modal__cabecera' }, tituloModal,
				el('button', { type: 'button', class: 'gsitg-modal__cerrar', 'aria-label': 'Cerrar detalle de alerta', text: '×', onclick: () => modal.close() })),
			cuerpoModal)
		modal.addEventListener('close', () => {
			if (activadorModal && activadorModal.isConnected) activadorModal.focus()
			activadorModal = null
		})
		modal.addEventListener('click', (ev) => { if (ev.target === modal) modal.close() })

		const opcionesRegla = [['', 'Todas las reglas']]
			.concat(estado.reglas.map((r) => [r.id, r.nombre]))
			.concat(Object.entries(OTRAS_REGLAS))

		const cambiar = (clave) => (ev) => {
			filtros[clave] = ev.target.value
			filtros.pagina = 1
			cargarAlertas()
		}
		let espera = null
		const autor = el('input', {
			type: 'search', placeholder: 'Usuario', value: filtros.autor, 'aria-label': 'Filtrar por usuario',
			oninput: (ev) => {
				clearTimeout(espera)
				espera = setTimeout(() => { filtros.autor = ev.target.value; filtros.pagina = 1; cargarAlertas() }, 400)
			},
		})
		const auto = el('input', {
			type: 'checkbox', id: 'gsitg-auto',
			onchange: (ev) => {
				clearInterval(temporizadorAuto)
				if (ev.target.checked) {
					temporizadorAuto = setInterval(cargarAlertas, 60000)
				}
			},
		})

		p.append(
			el('div', { class: 'gsitg-filtros' },
				seleccion(filtros.dias, [['1', 'Últimas 24 horas'], ['7', 'Últimos 7 días'], ['30', 'Últimos 30 días']],
					{ 'aria-label': 'Período', onchange: cambiar('dias') }),
				seleccion(filtros.severidad, [['baja', 'Todas las severidades'], ['media', 'Media o mayor'], ['alta', 'Alta o mayor'], ['critica', 'Solo crítica']],
					{ 'aria-label': 'Severidad', onchange: cambiar('severidad') }),
				seleccion(filtros.regla, opcionesRegla, { 'aria-label': 'Regla', onchange: cambiar('regla') }),
				seleccion(filtros.accion, [['', 'Todas las acciones']].concat(Object.entries(ACCION)),
					{ 'aria-label': 'Acción', onchange: cambiar('accion') }),
				autor,
				el('button', { type: 'button', text: 'Actualizar', onclick: () => cargarAlertas() }),
				el('label', { class: 'gsitg-auto', for: 'gsitg-auto' }, auto, ' Actualizar cada minuto'),
			),
			el('div', { class: 'gsitg-resumen', id: 'gsitg-resumen' }),
			el('div', { class: 'gsitg-tabla-envoltura' }, el('table', { class: 'gsitg-tabla', id: 'gsitg-tabla' })),
			el('div', { class: 'gsitg-paginas', id: 'gsitg-paginas' }),
			modal,
		)
	}

	let consultaActual = 0
	async function cargarAlertas() {
		// Si se cambian filtros rápido, solo se dibuja la respuesta de la última consulta.
		const numero = ++consultaActual
		try {
			if (!estado) {
				await cargarEstado()
			}
			if (!document.getElementById('gsitg-tabla')) {
				dibujarAlertas()
			}
			const datos = await api('alertas', { parametros: { ...filtros, porPagina: 50 } })
			if (numero !== consultaActual) {
				return
			}
			dibujarResumen(datos)
			dibujarTabla(datos)
		} catch (e) {
			aviso(e.message, 'error')
		}
	}

	function dibujarResumen(datos) {
		const r = datos.resumen
		const caja = document.getElementById('gsitg-resumen')
		const periodo = { 1: 'últimas 24 horas', 7: 'últimos 7 días', 30: 'últimos 30 días' }[filtros.dias]
		const tarjetas = ['critica', 'alta', 'media', 'baja'].map((s) =>
			el('button', {
				type: 'button',
				class: 'gsitg-tarjeta gsitg-tarjeta--' + s + (filtros.severidad === s ? ' gsitg-tarjeta--activa' : ''),
				title: 'Mostrar ' + SEVERIDAD[s].toLowerCase() + ' o mayor',
				onclick: () => {
					filtros.severidad = s
					filtros.pagina = 1
					dibujarAlertas()
					cargarAlertas()
				},
			},
			el('span', { class: 'gsitg-tarjeta-num', text: String(r.por_severidad[s] || 0) }),
			el('span', { class: 'gsitg-tarjeta-txt', text: SEVERIDAD[s] })))
		const reglas = Object.entries(r.por_regla).slice(0, 3)
			.map(([id, n]) => nombreRegla(id) + ' (' + n + ')').join(' · ')
		caja.replaceChildren(
			el('div', { class: 'gsitg-tarjetas' }, tarjetas),
			el('p', { class: 'gsitg-nota' },
				r.total + ' alertas en las ' + periodo + '.',
				reglas ? ' Reglas más frecuentes: ' + reglas + '.' : '',
				datos.truncado ? ' Se muestran las 50 000 más recientes.' : ''),
		)
	}

	function dibujarTabla(datos) {
		const tabla = document.getElementById('gsitg-tabla')
		const cabecera = el('thead', {}, el('tr', {},
			['Fecha', 'Severidad', 'Regla', 'Acción', 'Autor', 'Sala o detalle', 'Archivo'].map((t) => el('th', { scope: 'col', text: t }))))
		const cuerpo = el('tbody')
		if (datos.alertas.length === 0) {
			cuerpo.append(el('tr', {}, el('td', { colspan: '7', class: 'gsitg-vacio', text: 'No hay alertas con estos filtros.' })))
		}
		for (const a of datos.alertas) {
			const fila = el('tr', { class: 'gsitg-fila', tabindex: '0', 'aria-haspopup': 'dialog', 'aria-controls': 'gsitg-alerta-modal' },
				el('td', { text: fecha(a.detectado) }),
				el('td', {}, insignia(a.severidad)),
				el('td', { text: nombreRegla(a.regla_id) }),
				el('td', {}, el('span', { class: 'gsitg-accion gsitg-accion--' + a.accion, text: ACCION[a.accion] || a.accion || '—' })),
				el('td', { text: a.autor || '—' }),
				el('td', { class: 'gsitg-sala', text: a.sala_nombre || a.detalle || (a.caso ? 'Caso ' + a.caso : '—') }),
				el('td', { text: a.archivo || '' }),
			)
			const abrir = () => {
				const modal = panel('alertas').querySelector('.gsitg-modal')
				const titulo = modal.querySelector('#gsitg-modal-titulo')
				const contenido = modal.querySelector('.gsitg-modal__contenido')
				titulo.textContent = 'Alerta: ' + (a.regla || nombreRegla(a.regla_id) || a.regla_id || 'sin regla')
				contenido.replaceChildren(detalleAlerta(a))
				activadorModal = fila
				modal.showModal()
				modal.querySelector('.gsitg-modal__cerrar').focus()
			}
			fila.addEventListener('click', abrir)
			fila.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir() } })
			cuerpo.append(fila)
		}
		tabla.replaceChildren(cabecera, cuerpo)

		const paginas = document.getElementById('gsitg-paginas')
		paginas.replaceChildren(
			el('button', { type: 'button', text: '‹ Anterior', disabled: datos.pagina <= 1, onclick: () => { filtros.pagina--; cargarAlertas() } }),
			el('span', { text: 'Página ' + datos.pagina + ' de ' + datos.paginas + ' · ' + datos.total + ' alertas' }),
			el('button', { type: 'button', text: 'Siguiente ›', disabled: datos.pagina >= datos.paginas, onclick: () => { filtros.pagina++; cargarAlertas() } }),
		)
	}

	function detalleAlerta(a) {
		const etiquetas = {
			origen: 'Origen', alerta_id: 'Identificador de alerta', detectado: 'Fecha de detección', nivel: 'Nivel',
			evento: 'Evento', regla_id: 'Identificador de regla', regla: 'Regla', severidad: 'Severidad', accion: 'Acción',
			coincidencias: 'Cantidad de coincidencias', fragmentos: 'Fragmentos detectados', autor: 'Autor', tipo_autor: 'Tipo de autor',
			mensaje_id: 'Identificador del mensaje', fecha_mensaje: 'Fecha del mensaje', sala_token: 'Token de sala',
			sala_nombre: 'Sala', sala_tipo: 'Tipo de sala', participantes: 'Participantes', archivo: 'Archivo',
			evidencia: 'Identificador de evidencia', clave_id: 'ID de clave', huella_sha256: 'Huella SHA-256',
			nota: 'Nota', detalle: 'Detalle', caso: 'Caso', solicitante: 'Solicitante', ip: 'Dirección IP',
		}
		const valor = (clave, dato) => {
			if (clave === 'detectado' || clave === 'fecha_mensaje') return fecha(dato)
			if (Array.isArray(dato)) return dato.length ? dato.join(', ') : '—'
			if (dato && typeof dato === 'object') return JSON.stringify(dato, null, 2)
			return String(dato)
		}
		const datos = Object.entries(a).filter(([, v]) => v !== null && v !== undefined && v !== '')
		const lista = el('dl', { class: 'gsitg-dl gsitg-dl--modal' }, datos.map(([k, v]) => [
			el('dt', { text: etiquetas[k] || k }),
			el('dd', { text: valor(k, v) }),
		]))
		if (!a.evidencia || a.regla_id === 'acceso_evidencia') {
			return lista
		}
		const comando = 'docker exec -it -u www-data CONTENEDOR php occ gsi-talk-guard:evidencia:leer ' + a.evidencia +
			' --caso CORRELATIVO --solicitante USUARIO'
		return el('div', {}, lista,
			el('p', { class: 'gsitg-nota', text: 'Hay una copia cifrada del mensaje original. Solo puede leerse en el servidor, con la clave privada y dentro de un caso autorizado:' }),
			el('div', { class: 'gsitg-comando' },
				el('code', { text: comando }),
				el('button', {
					type: 'button', text: 'Copiar',
					onclick: () => navigator.clipboard.writeText(comando).then(() => aviso('Comando copiado.'), () => aviso('No se pudo copiar.', 'error')),
				})))
	}

	// ------------------------------------------------------------------
	// Pestaña Configuración
	// ------------------------------------------------------------------

	function tarjeta(titulo, descripcion, ...contenido) {
		return el('section', { class: 'gsitg-caja' },
			el('h3', { text: titulo }),
			descripcion ? el('p', { class: 'gsitg-nota', text: descripcion }) : null,
			contenido)
	}

	function dibujarConfig() {
		const p = panel('config')
		const hayEnmascaramiento = estado.reglas.some((r) => r.accion === 'enmascarar')

		// Modo
		const selModo = seleccion(estado.modo, Object.entries(MODOS).map(([k, [n]]) => [k, n]), { 'aria-label': 'Modo' })
		const descModo = el('p', { class: 'gsitg-nota', text: MODOS[estado.modo][1] })
		selModo.addEventListener('change', () => { descModo.textContent = MODOS[selModo.value][1] })
		const guardarModo = async (modo) => {
			try {
				await api('modo', { metodo: 'POST', datos: { modo } })
				aviso('Modo cambiado a «' + MODOS[modo][0] + '».')
				await cargarEstado()
				dibujarConfig()
			} catch (e) {
				aviso(e.message, 'error')
			}
		}
		const cajaModo = tarjeta('Modo de funcionamiento', null,
			el('div', { class: 'gsitg-fila-form' },
				el('span', { class: 'gsitg-modo gsitg-modo--' + estado.modo, text: 'Actual: ' + MODOS[estado.modo][0] }),
				selModo,
				el('button', {
					type: 'button', class: 'primary', text: 'Cambiar modo',
					onclick: () => {
						if (selModo.value === estado.modo) {
							return
						}
						if (selModo.value === 'desactivado' && !window.confirm('Con el modo desactivado, Talk no tendrá ninguna protección ni alertas. ¿Continuar?')) {
							return
						}
						guardarModo(selModo.value)
					},
				})),
			descModo,
			estado.modo === 'normal' && hayEnmascaramiento
				? el('button', {
					type: 'button', class: 'gsitg-emergencia', text: 'Detener el ocultamiento ahora',
					onclick: () => {
						if (window.confirm('Se dejará de ocultar datos de inmediato. Las alertas seguirán funcionando. ¿Continuar?')) {
							guardarModo('solo_alertar')
						}
					},
				})
				: null,
			el('p', { class: 'gsitg-nota' },
				'Clave de evidencias: ',
				el('strong', { text: estado.clave_publica ? 'configurada' : 'NO configurada' }),
				estado.clave_publica ? '' : '. Sin ella no se ocultan datos ni se guardan evidencias. Se genera solo en el servidor: occ gsi-talk-guard:claves:generar'),
		)

		// Alcance y notificaciones
		const txtPiloto = el('textarea', { rows: '3', placeholder: 'Vacío = todos los usuarios', 'aria-label': 'Usuarios del piloto' })
		txtPiloto.value = estado.usuarios_piloto.join('\n')
		const txtCorreo = el('textarea', { rows: '2', placeholder: 'Vacío = sin correo', 'aria-label': 'Correos de alerta' })
		txtCorreo.value = estado.correo_para.join('\n')
		const selSev = seleccion(estado.severidad_minima_correo,
			[['alta', 'Alta o mayor'], ['media', 'Media o mayor'], ['baja', 'Todas']], { 'aria-label': 'Severidad mínima del correo' })
		const chkAvisar = el('input', { type: 'checkbox', id: 'gsitg-avisar' })
		chkAvisar.checked = estado.avisar_usuario
		const cajaAlcance = tarjeta('Alcance y notificaciones', null,
			el('label', { class: 'gsitg-etiqueta', text: 'Usuarios del piloto (uno por línea, identificador interno de Nextcloud)' }), txtPiloto,
			el('label', { class: 'gsitg-etiqueta', text: 'Enviar alertas por correo a (uno por línea)' }), txtCorreo,
			el('div', { class: 'gsitg-fila-form' }, el('label', { class: 'gsitg-etiqueta', text: 'Severidad mínima del correo' }), selSev),
			el('div', { class: 'gsitg-fila-form' }, chkAvisar, el('label', { for: 'gsitg-avisar', text: 'Avisar al autor cuando se oculte un dato de su mensaje' })),
			el('button', {
				type: 'button', class: 'primary', text: 'Guardar',
				onclick: async () => {
					try {
						const r = await api('config', {
							metodo: 'POST',
							datos: {
								usuarios_piloto: lineas(txtPiloto.value),
								correo_para: lineas(txtCorreo.value),
								severidad_minima_correo: selSev.value,
								avisar_usuario: chkAvisar.checked,
							},
						})
						aviso(r.cambios ? 'Configuración guardada.' : 'No había cambios que guardar.')
						await cargarEstado()
					} catch (e) {
						aviso(e.message, 'error')
					}
				},
			}))

		// Dominios
		const txtDom = el('textarea', { rows: '3', 'aria-label': 'Dominios institucionales' })
		txtDom.value = estado.dominios.join('\n')
		const cajaDominios = tarjeta('Dominios institucionales',
			'Los correos y enlaces de estos dominios (y sus subdominios) no generan alertas de destino externo.',
			txtDom,
			el('button', {
				type: 'button', class: 'primary', text: 'Guardar dominios',
				onclick: async () => {
					try {
						const r = await api('dominios', { metodo: 'POST', datos: { dominios: lineas(txtDom.value) } })
						txtDom.value = r.dominios.join('\n')
						aviso('Dominios guardados.')
					} catch (e) {
						aviso(e.message, 'error')
					}
				},
			}))

		// Reglas personalizables para el alcance actual de Talk.
		const editorRegla = el('div', { class: 'gsitg-editor-regla', hidden: true })
		const cerrarEditor = () => { editorRegla.hidden = true; editorRegla.replaceChildren() }
		const abrirEditor = (r = null) => {
			const editando = Boolean(r)
			const campo = (id, etiqueta, control) => el('div', { class: 'gsitg-campo' },
				el('label', { for: id, class: 'gsitg-etiqueta', text: etiqueta }), control)
			const nombre = el('input', { id: 'gsitg-r-nombre', type: 'text', maxlength: '120', required: true, value: r?.nombre || '' })
			const id = el('input', { id: 'gsitg-r-id', type: 'text', maxlength: '40', required: true, value: r?.id || '', disabled: editando, pattern: '[a-z0-9_]+' })
			const descripcion = el('input', { id: 'gsitg-r-desc', type: 'text', maxlength: '240', value: r?.descripcion || '', placeholder: 'Contexto para quienes revisen la alerta' })
			const tipo = seleccion(r?.tipo || 'palabras', [['palabras', 'Lista de palabras o frases'], ['regex', 'Expresión regular']], { id: 'gsitg-r-tipo' })
			const definicion = el('textarea', { id: 'gsitg-r-def', rows: '5', required: true, placeholder: 'Una palabra o frase por línea' })
			definicion.value = r ? (r.tipo === 'palabras' ? r.palabras.join('\n') : r.definicion) : ''
			const coincidencia = seleccion(r?.coincidencia || 'cualquiera', [['cualquiera', 'Cualquiera de las frases'], ['todas', 'Todas las frases']], { id: 'gsitg-r-coincidencia' })
			const umbral = el('input', { id: 'gsitg-r-umbral', type: 'number', min: '1', max: '100', value: String(r?.umbral || 1) })
			const severidad = seleccion(r?.severidad || 'media', [['baja', 'Baja'], ['media', 'Media'], ['alta', 'Alta'], ['critica', 'Crítica']], { id: 'gsitg-r-sev' })
			const accion = seleccion(r?.accion || 'alertar', [['alertar', 'Solo alertar'], ['enmascarar', 'Ocultar coincidencia y alertar']], { id: 'gsitg-r-accion' })
			const mascara = seleccion(r?.mascara || 'total', [['total', 'Reemplazar completamente'], ['parcial', 'Ocultar parte'], ['digitos', 'Enmascarar dígitos']], { id: 'gsitg-r-mascara' })
			const validarLuhn = el('input', { id: 'gsitg-r-luhn', type: 'checkbox' }); validarLuhn.checked = r?.validar === 'luhn'
			const excluirDominios = el('input', { id: 'gsitg-r-dominios', type: 'checkbox' }); excluirDominios.checked = r?.excluir_dominios_institucionales ?? false
			const reemplazo = el('input', { id: 'gsitg-r-reemplazo', type: 'text', maxlength: '120', value: r?.reemplazo || '', placeholder: '[dato oculto]' })
			const habilitada = el('input', { id: 'gsitg-r-enabled', type: 'checkbox' }); habilitada.checked = r?.habilitada ?? true
			const eventoChecks = [['mensaje', 'Mensajes'], ['edicion', 'Ediciones'], ['archivo', 'Archivos compartidos']].map(([v, t]) => {
				const check = el('input', { type: 'checkbox', value: v }); check.checked = r ? r.eventos.includes(v) : true
				return el('label', { class: 'gsitg-check' }, check, ' ' + t)
			})
			const ayuda = el('p', { class: 'gsitg-nota' })
			const actualizarTipo = () => {
				const palabras = tipo.value === 'palabras'
				coincidencia.parentElement.hidden = !palabras
				definicion.placeholder = palabras ? 'Una palabra o frase por línea (hasta 100)' : 'Patrón PCRE, por ejemplo: \\b[A-Z]{3}-\\d{6}\\b'
				umbral.parentElement.hidden = palabras
				const puedeOcultar = tipo.value === 'regex'
				accion.querySelector('option[value="enmascarar"]').disabled = !puedeOcultar || !estado.clave_publica
				if (!puedeOcultar && accion.value === 'enmascarar') accion.value = 'alertar'
				mascara.parentElement.hidden = accion.value !== 'enmascarar'
				validarLuhn.parentElement.hidden = !puedeOcultar
				excluirDominios.parentElement.hidden = !puedeOcultar
				reemplazo.parentElement.hidden = accion.value !== 'enmascarar' || mascara.value !== 'total'
				ayuda.textContent = palabras
					? 'Las frases se comparan sin distinguir mayúsculas ni acentos. “Todas” exige que cada frase aparezca en el mismo texto.'
					: 'La expresión se ejecuta sobre cada evento seleccionado. El umbral cuenta coincidencias en un solo texto.'
			}
			tipo.addEventListener('change', actualizarTipo)
			accion.addEventListener('change', actualizarTipo)
			mascara.addEventListener('change', actualizarTipo)
			const guardar = el('button', { type: 'submit', class: 'primary', text: editando ? 'Guardar cambios' : 'Crear regla' })
			const form = el('form', { class: 'gsitg-form-regla' },
				el('h4', { text: editando ? 'Editar regla' : 'Nueva regla' }),
				el('div', { class: 'gsitg-grid-regla' },
					campo('gsitg-r-nombre', 'Nombre visible', nombre),
					campo('gsitg-r-id', 'Identificador', id),
					campo('gsitg-r-desc', 'Descripción opcional', descripcion),
					campo('gsitg-r-tipo', 'Cómo detectar', tipo),
					campo('gsitg-r-def', 'Frases o patrón', definicion),
					campo('gsitg-r-coincidencia', 'Coincidencia de frases', coincidencia),
					campo('gsitg-r-umbral', 'Coincidencias mínimas por evento', umbral),
					campo('gsitg-r-sev', 'Severidad', severidad),
					campo('gsitg-r-accion', 'Acción', accion),
					campo('gsitg-r-mascara', 'Tipo de ocultamiento', mascara),
					campo('gsitg-r-reemplazo', 'Texto de reemplazo (opcional)', reemplazo),
					el('div', { class: 'gsitg-campo' }, el('label', { class: 'gsitg-check' }, validarLuhn, ' Validar con algoritmo Luhn (tarjetas)')),
					el('div', { class: 'gsitg-campo' }, el('label', { class: 'gsitg-check' }, excluirDominios, ' Excluir dominios institucionales'))),
				el('fieldset', { class: 'gsitg-eventos' }, el('legend', { text: 'Aplicar a' }), eventoChecks),
				el('label', { class: 'gsitg-check' }, habilitada, ' Regla habilitada'), ayuda,
				el('div', { class: 'gsitg-fila-form' }, guardar,
					el('button', { type: 'button', text: 'Cancelar', onclick: cerrarEditor })))
			id.addEventListener('input', () => { id.value = id.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').slice(0, 40) })
			if (!editando) nombre.addEventListener('input', () => {
				if (!id.dataset.tocado) id.value = nombre.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40)
			})
			id.addEventListener('change', () => { id.dataset.tocado = '1' })
			form.addEventListener('submit', async (ev) => {
				ev.preventDefault()
				const esPalabras = tipo.value === 'palabras'
				const expresiones = esPalabras ? lineas(definicion.value) : definicion.value.trim()
				const eventos = eventoChecks.filter((x) => x.querySelector('input').checked).map((x) => x.querySelector('input').value)
				if (!form.reportValidity()) return
				if (!eventos.length || (esPalabras && !expresiones.length)) { aviso('Seleccione al menos un evento e indique las frases.', 'error'); return }
				const regla = {
					id: editando ? r.id : id.value, nombre: nombre.value.trim(), descripcion: descripcion.value.trim(),
					descripcion_usuario: r?.descripcion_usuario || nombre.value.trim().toLowerCase(),
					tipo: tipo.value, severidad: severidad.value, accion: accion.value, habilitada: habilitada.checked,
					eventos, umbral: Number(umbral.value),
					...(esPalabras
						? { palabras: expresiones, coincidencia: coincidencia.value }
						: { patron: expresiones, validar: validarLuhn.checked ? 'luhn' : null, excluir_dominios_institucionales: excluirDominios.checked,
							mascara: mascara.value,
							reemplazo: mascara.value === 'total' && reemplazo.value ? reemplazo.value : null }),
				}
				if (accion.value === 'enmascarar' && !window.confirm('La regla ocultará coincidencias antes de que aparezcan en Talk. Confirme que esto fue comunicado y aprobado.')) return
				try {
					await api('guardarRegla', { metodo: 'POST', datos: { regla, id_original: r?.id || '' } })
					aviso('Regla «' + regla.nombre + '» guardada.')
					await cargarEstado(); dibujarConfig()
				} catch (e) { aviso(e.message, 'error') }
			})
			editorRegla.replaceChildren(form)
			editorRegla.hidden = false
			actualizarTipo()
		}
		const filas = estado.reglas.map((r) => el('tr', {},
			el('td', {}, el('strong', { text: r.nombre }), el('br'), el('code', { class: 'gsitg-id', text: r.id }),
				r.descripcion ? el('p', { class: 'gsitg-nota', text: r.descripcion }) : null,
				el('p', { class: 'gsitg-nota', text: (r.habilitada ? 'Habilitada' : 'Deshabilitada') + ' · ' + r.eventos.join(', ') })),
			el('td', {}, insignia(r.severidad)),
			el('td', { class: 'gsitg-num', text: String(r.alertas_7_dias) }),
			el('td', {},
				el('button', { type: 'button', text: 'Editar', onclick: () => abrirEditor(r) }), ' ',
				el('button', { type: 'button', text: r.habilitada ? 'Deshabilitar' : 'Habilitar', onclick: async () => {
					try {
						await api('guardarRegla', { metodo: 'POST', datos: { regla: { ...r, habilitada: !r.habilitada, patron: r.definicion,
							validar: r.validar, excluir_dominios_institucionales: r.excluir_dominios_institucionales }, id_original: r.id } })
						await cargarEstado(); dibujarConfig(); aviso('Estado de regla actualizado.')
					} catch (e) { aviso(e.message, 'error') }
				} }), ' ',
				el('button', { type: 'button', text: 'Eliminar', onclick: async () => {
					if (!window.confirm('¿Eliminar la regla «' + r.nombre + '»?')) return
					try { await api('eliminarRegla', { metodo: 'POST', datos: { id: r.id } }); await cargarEstado(); dibujarConfig(); aviso('Regla eliminada.') }
					catch (e) { aviso(e.message, 'error') }
				} }))))
		const cajaReglas = tarjeta('Reglas',
			'Las reglas se ejecutan en Nextcloud Talk y generan alertas locales. Se admite coincidencia por frases o expresiones regulares, con alcance por tipo de evento. El ocultamiento requiere una expresión regular y una clave de evidencias configurada.',
			el('button', { type: 'button', class: 'primary', text: 'Crear regla', onclick: () => abrirEditor() }),
			editorRegla,
			el('div', { class: 'gsitg-tabla-envoltura' },
				el('table', { class: 'gsitg-tabla' },
					el('thead', {}, el('tr', {}, ['Regla', 'Severidad', 'Alertas (7 días)', 'Acciones'].map((t) => el('th', { scope: 'col', text: t })))),
					el('tbody', {}, filas))))

		p.replaceChildren(cajaModo, cajaAlcance, cajaDominios, cajaReglas)
	}

	// ------------------------------------------------------------------
	// Pestaña Probar reglas
	// ------------------------------------------------------------------

	function dibujarProbar() {
		const p = panel('probar')
		const texto = el('textarea', { rows: '4', placeholder: 'Escriba un mensaje de ejemplo, por ejemplo: la cuenta es 0102-0000-00-0000000019', 'aria-label': 'Texto a probar' })
		const evento = seleccion('mensaje', [['mensaje', 'Mensaje nuevo'], ['edicion', 'Edición'], ['archivo', 'Archivo compartido']], { 'aria-label': 'Tipo de evento' })
		const resultado = el('div', { class: 'gsitg-resultado', 'aria-live': 'polite' })
		p.replaceChildren(
			tarjeta('Probar reglas', 'El texto no se envía a Talk ni genera alertas. Use solo datos ficticios. Seleccione el tipo de evento para probar su alcance.',
				texto,
				evento,
				el('button', {
					type: 'button', class: 'primary', text: 'Probar',
					onclick: async () => {
						try {
							const r = await api('probar', { metodo: 'POST', datos: { texto: texto.value, evento: evento.value } })
							dibujarResultado(resultado, r)
						} catch (e) {
							aviso(e.message, 'error')
						}
					},
				}),
				resultado))
	}

	function dibujarResultado(caja, r) {
		if (r.hallazgos.length === 0) {
			caja.replaceChildren(el('p', { class: 'gsitg-ok', text: 'Sin coincidencias: este mensaje no generaría alertas.' }))
			return
		}
		const lista = el('ul', { class: 'gsitg-hallazgos' }, r.hallazgos.map((h) =>
			el('li', {}, insignia(h.severidad), ' ', el('strong', { text: h.regla }),
				' · ' + (h.accion === 'enmascarar' ? 'ocultar' : 'alertar') + ' · ',
				el('code', { text: h.fragmentos.join('   ') }))))
		const partes = [el('p', { text: r.hallazgos.length + (r.hallazgos.length === 1 ? ' regla coincide:' : ' reglas coinciden:') }), lista]
		if (r.enmascarado !== null) {
			partes.push(
				el('p', { text: r.se_aplicaria ? 'Los participantes verían:' : 'Si el ocultamiento estuviera activo, los participantes verían:' }),
				el('blockquote', { class: 'gsitg-vista', text: r.enmascarado }))
		}
		caja.replaceChildren(...partes)
	}

	// ------------------------------------------------------------------
	cargarAlertas()
})()
