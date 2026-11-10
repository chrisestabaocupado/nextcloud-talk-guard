/**
 * Prueba de la página de administración de GSI Talk Guard en un navegador
 * simulado (jsdom), contra un Nextcloud real. SOLO para el entorno de prueba:
 * cambia la configuración durante la prueba y la restaura al final.
 *
 * Uso:
 *   cd pruebas/ui && npm install
 *   NC_URL=http://127.0.0.1:8080 NC_DIR=/tmp/nc-prueba/nextcloud node prueba_ui.js
 *
 * Variables: NC_URL, NC_DIR (carpeta de Nextcloud, para occ),
 *            ADMIN_USUARIO (admin), ADMIN_CLAVE (Admin.Prueba.2026)
 */
const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')
const { JSDOM } = require('jsdom')

const BASE = process.env.NC_URL || 'http://127.0.0.1:8080'
const NC_DIR = process.env.NC_DIR || '/tmp/nc-prueba/nextcloud'
const ADMIN = process.env.ADMIN_USUARIO || 'admin'
const CLAVE = process.env.ADMIN_CLAVE || 'Admin.Prueba.2026'
const JS_APP = path.join(__dirname, '../../app/gsi_talk_guard/js/admin.js')

const occ = (...args) => execFileSync('php', [path.join(NC_DIR, 'occ'), ...args]).toString()

// --- Sesión de navegador con cookies -------------------------------------
const galletas = new Map()
const cabeceraCookie = () => [...galletas].map(([k, v]) => k + '=' + v).join('; ')
async function pedir(url, op = {}) {
	const r = await fetch(new URL(url, BASE), {
		...op, redirect: 'manual', headers: { ...(op.headers || {}), Cookie: cabeceraCookie() },
	})
	for (const c of r.headers.getSetCookie()) {
		const [par] = c.split(';')
		const i = par.indexOf('=')
		const nombre = par.slice(0, i).trim()
		const valor = par.slice(i + 1)
		if (/expires=Thu, 01 Jan 1970/i.test(c) || valor === 'deleted') galletas.delete(nombre)
		else galletas.set(nombre, valor)
	}
	return r
}
const tokenDe = (html) => (html.match(/data-requesttoken="([^"]*)"/) || [])[1] || ''

async function iniciarSesion() {
	const token = tokenDe(await (await pedir('/index.php/login')).text())
	const r = await pedir('/index.php/login', {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded', Origin: BASE },
		body: new URLSearchParams({ user: ADMIN, password: CLAVE, requesttoken: token, timezone: 'America/Caracas' }),
	})
	if (!/dashboard|apps\//.test(r.headers.get('location') || '')) {
		throw new Error('No se pudo iniciar sesión como ' + ADMIN)
	}
	const pagina = await pedir('/index.php/settings/admin/gsi_talk_guard')
	if (pagina.status !== 200) throw new Error('La página de administración respondió ' + pagina.status)
	return pagina.text()
}

// --- Utilidades de prueba -------------------------------------------------
const espera = (ms) => new Promise((r) => setTimeout(r, ms))
async function hasta(cond, desc, ms = 8000) {
	const fin = Date.now() + ms
	while (Date.now() < fin) { if (cond()) return; await espera(50) }
	throw new Error('Tiempo agotado esperando: ' + desc)
}
let ok = 0
let fallas = 0
const verificar = (desc, cond) => {
	if (cond) { ok++; console.log('  OK     ' + desc) } else { fallas++; console.log('  FALLA  ' + desc) }
}

;(async () => {
	// Estado inicial conocido; se restaura al final.
	const reglasOriginales = occ('gsi-talk-guard:reglas:exportar')
	const eranPredeterminadas = occ('gsi-talk-guard:estado').includes('predeterminadas')
	const modoOriginal = occ('gsi-talk-guard:estado').match(/Modo:\s+(\S+)/)[1]
	let pilotoOriginal = ''
	try { pilotoOriginal = occ('config:app:get', 'gsi_talk_guard', 'usuarios_piloto').trim() } catch (e) { /* no definido */ }
	const reglas = JSON.parse(reglasOriginales)
	for (const r of reglas.reglas) if (['tarjeta', 'cuenta_bancaria'].includes(r.id)) r.accion = 'enmascarar'
	const tmp = path.join(require('os').tmpdir(), 'gsitg-reglas-prueba.json')
	fs.writeFileSync(tmp, JSON.stringify(reglas))
	occ('gsi-talk-guard:reglas:importar', tmp)
	occ('gsi-talk-guard:modo', 'normal')
	occ('config:app:set', 'gsi_talk_guard', 'usuarios_piloto', '--value=ana.perez,luis.rojas')

	// Alerta con contenido malicioso: debe mostrarse como texto, nunca ejecutarse.
	const datos = occ('config:system:get', 'datadirectory').trim()
	const archivo = path.join(datos, 'gsi_talk_guard', 'alertas.jsonl')
	fs.mkdirSync(path.dirname(archivo), { recursive: true })
	fs.appendFileSync(archivo, JSON.stringify({
		origen: 'talk-guard', detectado: new Date().toISOString(), alerta_id: 'prueba-xss', evento: 'mensaje',
		regla_id: 'exfiltracion', severidad: 'media', nivel: 2, accion: 'alerta', autor: '<b>atacante</b>',
		sala_nombre: '<img src=x onerror="window.__xss=1">Sala', participantes: ['a', 'b'],
		fragmentos: ['<script>window.__xss=2</script>'],
	}) + '\n')
	// Y una alerta alta con evidencia, para probar el detalle.
	fs.appendFileSync(archivo, JSON.stringify({
		origen: 'talk-guard', detectado: new Date().toISOString(), alerta_id: 'prueba-1-cuenta_bancaria', evento: 'mensaje',
		regla_id: 'cuenta_bancaria', severidad: 'alta', nivel: 3, accion: 'enmascarado', autor: 'ana.perez',
		sala_nombre: 'Finanzas', sala_tipo: 'grupo', participantes: ['ana.perez', 'luis.rojas'], num_participantes: 2,
		fragmentos: ['0102****6789'], evidencia: 'prueba-1',
	}) + '\n')

	const html = await iniciarSesion()
	const dom = new JSDOM(html, { url: BASE + '/index.php/settings/admin/gsi_talk_guard', runScripts: 'outside-only', pretendToBeVisual: true })
	const w = dom.window
	const d = w.document
	w.fetch = (url, op = {}) => pedir(url, op)
	w.confirm = () => true
	w.navigator.clipboard = { writeText: () => Promise.resolve() }
	const elegir = (sel, valor) => { sel.value = valor; sel.dispatchEvent(new w.Event('change', { bubbles: true })) }
	const boton = (panel, texto) => [...d.querySelectorAll('[data-panel="' + panel + '"] button')].find((b) => b.textContent === texto)
	const filas = () => [...d.querySelectorAll('#gsitg-tabla tbody tr.gsitg-fila')]

	try {
		w.eval(fs.readFileSync(JS_APP, 'utf8'))

		console.log('Pestaña Alertas')
		await hasta(() => filas().length > 0, 'filas de alertas')
		verificar('muestra alertas de las últimas 24 horas (' + filas().length + ')', filas().length > 0)
		verificar('muestra 4 tarjetas de resumen', d.querySelectorAll('.gsitg-tarjeta').length === 4)
		const xss = filas().find((f) => f.textContent.includes('onerror'))
		verificar('el contenido malicioso se muestra como texto', !!xss && xss.textContent.includes('<img src=x'))
		verificar('no se creó ninguna imagen ni script desde los datos', d.querySelectorAll('#gsitg img, #gsitg script').length === 0 && !w.__xss)

		const [selDias, selSev, selRegla] = d.querySelectorAll('.gsitg-filtros select')
		elegir(selDias, '7')
		elegir(selSev, 'alta')
		await hasta(() => filas().length > 0 && filas().every((f) => /Alta|Crítica/.test(f.children[1].textContent)), 'filtro de severidad')
		verificar('el filtro «alta o mayor» deja solo alertas altas (' + filas().length + ')', true)
		verificar('la paginación indica el total', /Página 1 de \d+ · \d+ alertas/.test(d.getElementById('gsitg-paginas').textContent))

		const filaMensaje = filas().find((f) => f.children[2].textContent.startsWith('Número de'))
		filaMensaje.click()
		const det = filaMensaje.nextElementSibling
		verificar('el clic abre el detalle con participantes', !det.hidden && det.textContent.includes('Participantes'))
		verificar('el detalle no muestra «[object HTMLElement]»', !det.textContent.includes('[object'))
		verificar('el detalle ofrece el comando para leer la evidencia', det.textContent.includes('gsi-talk-guard:evidencia:leer'))

		console.log('Pestaña Configuración')
		d.querySelector('[data-tab="config"]').click()
		await hasta(() => d.querySelectorAll('[data-panel="config"] tbody tr').length === 10, 'tabla de reglas')
		verificar('muestra las 10 reglas', true)
		verificar('el modo actual es «Normal»', d.querySelector('.gsitg-modo').textContent.includes('Normal'))
		const emergencia = d.querySelector('.gsitg-emergencia')
		verificar('hay botón de emergencia porque hay reglas que ocultan', !!emergencia)
		emergencia.click()
		await hasta(() => d.querySelector('.gsitg-modo') && d.querySelector('.gsitg-modo').textContent.includes('Solo alertar'), 'modo de emergencia')
		verificar('el botón de emergencia cambia el servidor a «solo_alertar»', occ('gsi-talk-guard:estado').includes('solo_alertar'))
		elegir(d.querySelector('[data-panel="config"] select[aria-label="Modo"]'), 'normal')
		boton('config', 'Cambiar modo').click()
		await hasta(() => d.querySelector('.gsitg-modo').textContent.includes('Normal'), 'volver a normal')
		verificar('se puede volver al modo normal', /Modo:\s+normal/.test(occ('gsi-talk-guard:estado')))

		elegir(d.querySelector('select[aria-label="Acción de Número de tarjeta de pago"]'), 'alertar')
		await hasta(() => /tarjeta\s+\|\s+regex\s+\|\s+alta\s+\|\s+alertar/.test(occ('gsi-talk-guard:estado')), 'cambio de regla')
		verificar('cambiar la acción de una regla se guarda', true)
		await espera(800) // la página vuelve a dibujarse tras cambiar una regla
		verificar('las reglas de palabras muestran «Solo alerta»', [...d.querySelectorAll('[data-panel="config"] tbody tr')].filter((f) => f.textContent.includes('Solo alerta')).length === 6)

		const piloto = () => d.querySelectorAll('[data-panel="config"] textarea')[0]
		piloto().value = 'ana.perez\ncarla.diaz'
		boton('config', 'Guardar').click()
		await hasta(() => d.getElementById('gsitg-aviso').textContent === 'Configuración guardada.', 'aviso de guardado')
		verificar('guardar usuarios del piloto', occ('gsi-talk-guard:estado').includes('ana.perez, carla.diaz'))
		piloto().value = 'ana.perez\nusuario.inexistente'
		boton('config', 'Guardar').click()
		await hasta(() => d.getElementById('gsitg-aviso').textContent.includes('no existen'), 'error de usuario')
		verificar('un usuario inexistente muestra un error claro', d.getElementById('gsitg-aviso').className.includes('error'))

		console.log('Pestaña Probar reglas')
		d.querySelector('[data-tab="probar"]').click()
		const txt = d.querySelector('[data-panel="probar"] textarea')
		txt.value = 'la cuenta es 0102-0123-45-0123456789, pásamelo por whatsapp'
		boton('probar', 'Probar').click()
		await hasta(() => d.querySelectorAll('.gsitg-hallazgos li').length > 0, 'resultado')
		verificar('muestra las 2 reglas que coinciden', d.querySelectorAll('.gsitg-hallazgos li').length === 2)
		verificar('muestra cómo lo verían los participantes', (d.querySelector('.gsitg-vista') || {}).textContent === 'la cuenta es 0102****6789, pásamelo por whatsapp')
		txt.value = 'buenos días'
		boton('probar', 'Probar').click()
		await hasta(() => d.querySelector('.gsitg-ok'), 'sin coincidencias')
		verificar('un texto normal muestra «Sin coincidencias»', true)
	} catch (e) {
		fallas++
		console.log('  ERROR  ' + e.message)
	} finally {
		if (eranPredeterminadas) {
			occ('gsi-talk-guard:reglas:importar', '--predeterminadas')
		} else {
			fs.writeFileSync(tmp, reglasOriginales)
			occ('gsi-talk-guard:reglas:importar', tmp)
		}
		occ('gsi-talk-guard:modo', modoOriginal)
		if (pilotoOriginal) occ('config:app:set', 'gsi_talk_guard', 'usuarios_piloto', '--value=' + pilotoOriginal)
		else occ('config:app:delete', 'gsi_talk_guard', 'usuarios_piloto')
		fs.unlinkSync(tmp)
	}
	console.log('\nResultado: ' + ok + ' correctas, ' + fallas + ' fallas.')
	process.exit(fallas ? 1 : 0)
})().catch((e) => { console.log('  ERROR  ' + e.message); process.exit(2) })
