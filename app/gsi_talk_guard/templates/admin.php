<?php
/** @var array $_ */
?>
<div id="gsitg" class="section gsitg" data-rutas="<?php p(json_encode($_['rutas'])); ?>">
	<h2>GSI Talk Guard</h2>
	<p class="gsitg-sub">Detección y enmascaramiento de información sensible en Talk · Gerencia de Seguridad de la Información</p>

	<div class="gsitg-tabs" role="tablist">
		<button type="button" role="tab" class="gsitg-tab" data-tab="alertas" aria-selected="true">Alertas</button>
		<button type="button" role="tab" class="gsitg-tab" data-tab="config" aria-selected="false">Configuración</button>
		<button type="button" role="tab" class="gsitg-tab" data-tab="probar" aria-selected="false">Probar reglas</button>
	</div>

	<div class="gsitg-aviso" id="gsitg-aviso" role="status" aria-live="polite" hidden></div>

	<div class="gsitg-panel" data-panel="alertas" role="tabpanel"><p class="gsitg-cargando">Cargando…</p></div>
	<div class="gsitg-panel" data-panel="config" role="tabpanel" hidden><p class="gsitg-cargando">Cargando…</p></div>
	<div class="gsitg-panel" data-panel="probar" role="tabpanel" hidden></div>
</div>
