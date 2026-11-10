<?php

declare(strict_types=1);

// SOLO en el Nextcloud local de pruebas. Crea cuentas temporales y restaura la configuración.
if (getenv('GSITG_ENTORNO_PRUEBA') !== 'si') {
	fwrite(STDERR, "Defina GSITG_ENTORNO_PRUEBA=si; nunca ejecute esta prueba en producción.\n");
	exit(2);
}
$nc = getenv('NC_DIR') ?: '/tmp/nc-prueba/nextcloud';
require $nc . '/lib/base.php';
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApps();

use OCA\GsiTalkGuard\BackgroundJob\EnviarCorreoAlerta;
use OCA\GsiTalkGuard\Service\Alertas;
use OCA\GsiTalkGuard\Service\ContextoAlertas;
use OCA\GsiTalkGuard\Service\LectorAlertas;
use OCA\GsiTalkGuard\Service\Settings;
use OCP\Accounts\IAccountManager;
use OCP\Server;

$usuarios = Server::get(\OCP\IUserManager::class);
$cuentas = Server::get(IAccountManager::class);
$grupos = Server::get(\OCP\IGroupManager::class);
$url = Server::get(\OCP\IURLGenerator::class);
$config = Server::get(\OCP\IAppConfig::class);
$settings = Server::get(Settings::class);
$cola = Server::get(\OCP\BackgroundJob\IJobList::class);
$logger = new class extends \Psr\Log\AbstractLogger {
	public array $registros = [];
	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->registros[] = [$level, (string)$message, $context];
	}
};
$contexto = new ContextoAlertas($usuarios, $cuentas, $grupos, $url, $logger);
$original = [];
foreach (['archivo_alertas', 'correo_para', 'severidad_minima_correo'] as $clave) {
	$original[$clave] = $config->getValueString('gsi_talk_guard', $clave, '');
}
$archivo = tempnam(sys_get_temp_dir(), 'gsitg-identidad-');
$creados = [];
$argumentos = [];
$ok = 0;
$comprobar = static function (bool $condicion, string $descripcion) use (&$ok): void {
	if (!$condicion) {
		throw new \RuntimeException($descripcion);
	}
	$ok++;
	echo "  OK     $descripcion\n";
};
$fallo = false;
try {
	foreach (['Ana Pérez', 'Luis Rojas'] as $nombre) {
		$u = $usuarios->createUser('gsitg-' . bin2hex(random_bytes(8)), bin2hex(random_bytes(20)));
		$creados[] = $u;
		$u->setDisplayName($nombre);
		$u->setSystemEMailAddress(count($creados) === 1 ? 'ana@example.test' : 'luis@example.test');
	}
	[$ana, $luis] = $creados;
	$cuenta = $cuentas->getAccount($ana);
	$cuenta->setProperty(IAccountManager::PROPERTY_ROLE, 'Analista', IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
	$cuentas->updateAccount($cuenta);
	$ficha = $contexto->persona($ana->getUID());
	$comprobar($ficha['nombre'] === 'Ana Pérez' && $ficha['correo'] === 'ana@example.test' && $ficha['cargo'] === 'Analista', 'ficha real de Nextcloud: nombre, correo y cargo');
	$comprobar($contexto->persona($ana->getUID(), 'guests')['correo'] === '', 'un invitado no se confunde con una cuenta local del mismo ID');
	$comprobar($contexto->persona('gsitg-inexistente')['id'] === 'gsitg-inexistente', 'se conserva el ID de una cuenta no disponible');

	// LDAP simulado sobre las APIs reales: el UID interno no es sAMAccountName.
	require_once $nc . '/apps/user_ldap/lib/LDAPProvider.php';
	$ldap = new class extends \OCA\User_LDAP\LDAPProvider {
		public array $atributos = ['sAMAccountName' => 'ana.perez', 'department' => 'Seguridad'];
		public function __construct() {}
		public function getUserAttribute(string $uid, string $attribute): ?string { return $this->atributos[$attribute] ?? null; }
	};
	\OC::$server->registerService(\OCP\LDAP\ILDAPProvider::class, static fn () => $ldap);
	$usuarioLdap = new class($ana) extends \OC\User\User {
		public function __construct(private \OCP\IUser $real) {}
		public function getUID(): string { return $this->real->getUID(); }
		public function getDisplayName(): string { return $this->real->getDisplayName(); }
		public function getEMailAddress(): ?string { return $this->real->getEMailAddress(); }
		public function getBackendClassName(): string { return 'LDAP'; }
		public function isEnabled(): bool { return true; }
	};
	$usuariosLdap = new class($usuarioLdap) extends \OC\User\Manager {
		public function __construct(private \OCP\IUser $usuario) {}
		public function get($uid, array $excludeBackends = []): ?\OCP\IUser { return $uid === $this->usuario->getUID() ? $this->usuario : null; }
	};
	$contextoLdap = new ContextoAlertas($usuariosLdap, $cuentas, $grupos, $url, $logger);
	$ldapFicha = $contextoLdap->persona($ana->getUID());
	$comprobar($ldapFicha['usuario'] === 'ana.perez' && $ldapFicha['departamento'] === 'Seguridad'
		&& $ldapFicha['id'] !== 'ana.perez', 'LDAP: cuenta de AD y departamento separados del ID interno');

	$base = ['alerta_id' => 'identidad-' . bin2hex(random_bytes(8)), 'autor' => $ana->getUID(), 'tipo_autor' => 'users',
		'regla_id' => 'prueba', 'regla' => 'Prueba de identidad', 'severidad' => 'media', 'evento' => 'mensaje', 'accion' => 'alerta',
		'sala_nombre' => $ana->getUID() . ', ' . $luis->getUID(), 'sala_tipo' => 'uno_a_uno', 'sala_token' => 'prueba-identidad',
		'participantes' => [$ana->getUID(), $luis->getUID()], 'num_participantes' => 2, 'fragmentos' => ['FRAGMENTO_NO_ENVIAR']];
	$enriquecido = $contexto->enriquecer($base);
	$comprobar($enriquecido['sala_nombre'] === 'Ana Pérez — Luis Rojas' && str_contains($enriquecido['sala_url'], '/call/'), 'sala privada legible con enlace a Talk');
	$comprobar($enriquecido['autor'] === $ana->getUID() && $enriquecido['participantes'] === $base['participantes'], 'se conservan los IDs originales de auditoría');
	$comprobar($enriquecido['participantes_fichas'][1]['nombre'] === 'Luis Rojas', 'participantes con identidad legible');
	$ana->setDisplayName('Nombre posterior');
	$comprobar($contexto->enriquecer($enriquecido)['autor_ficha']['nombre'] === 'Ana Pérez', 'la ficha capturada no se sustituye por cambios posteriores');
	$config->setValueString('gsi_talk_guard', 'archivo_alertas', $archivo);
	$settings->guardarCorreoPara(['buzon@example.test']);
	$settings->guardarSeveridadMinimaCorreo('media');
	$alertas = new Alertas($settings, $cola, $logger, $contexto);
	$alertas->emitir($enriquecido);
	$persistido = json_decode(trim((string)file_get_contents($archivo)), true);
	$comprobar($persistido['autor_ficha']['nombre'] === 'Ana Pérez' && $persistido['sala_nombre'] === 'Ana Pérez — Luis Rojas', 'el JSONL conserva las fichas y el nombre de sala');
	$lector = new LectorAlertas($settings, $contexto);
	$comprobar($lector->consultar(1, ['autor' => 'ana@example.test'], 1, 10)['total'] === 1, 'el filtro acepta el correo del autor');
	$comprobar($lector->consultar(1, ['autor' => 'Ana Pérez'], 1, 10)['total'] === 1, 'el filtro acepta el nombre del autor');
	$historica = $base;
	$historica['alerta_id'] .= '-historica';
	$historica['autor'] = $luis->getUID();
	$historica['detectado'] = gmdate('c');
	file_put_contents($archivo, json_encode($historica) . "\n", FILE_APPEND);
	$antes = file_get_contents($archivo);
	$consultaHistorica = $lector->consultar(1, ['autor' => 'Luis Rojas'], 1, 10);
	$comprobar($consultaHistorica['total'] === 1 && $consultaHistorica['alertas'][0]['autor_ficha']['nombre'] === 'Luis Rojas', 'los registros históricos se buscan y consultan por nombre');
	$comprobar(file_get_contents($archivo) === $antes, 'consultar fichas históricas no reescribe el JSONL');
	$trabajos = array_filter($cola->getJobs(EnviarCorreoAlerta::class, null, 0), static fn ($j) => ($j->getArgument()['alerta_id'] ?? '') === $base['alerta_id']);
	$trabajo = array_values($trabajos)[0];
	$argumento = $trabajo->getArgument();
	$argumentos[] = $argumento;
	$comprobar($argumento['autor_ficha']['nombre'] === 'Ana Pérez' && strlen(json_encode($argumento)) < 32000, 'el trabajo encolado contiene identidad y respeta el límite de tamaño');
	$capturador = new class(Server::get(\OCP\Mail\IMailer::class)) extends \OC\Mail\Mailer {
		public array $mensajes = [];
		public array $fallidos = [];
		public function __construct(private \OCP\Mail\IMailer $real) {}
		public function createMessage(): \OC\Mail\Message { return $this->real->createMessage(); }
		public function send(\OCP\Mail\IMessage $message): array { $this->mensajes[] = $message; return $this->fallidos; }
	};
	$envio = new EnviarCorreoAlerta(Server::get(\OCP\AppFramework\Utility\ITimeFactory::class), $capturador, $settings, $logger, $contexto);
	$envio->setId($trabajo->getId());
	$envio->setArgument($argumento);
	$envio->start($cola);
	$texto = $capturador->mensajes[0]->getPlainBody();
	$comprobar(str_contains($texto, 'Ana Pérez') && str_contains($texto, 'Luis Rojas') && str_contains($texto, 'ana@example.test'), 'el correo incluye la persona y los participantes');
	$comprobar(!str_contains($texto, 'Wazuh') && !str_contains($texto, 'FRAGMENTO_NO_ENVIAR'), 'el correo remite al panel y no incluye fragmentos del mensaje');
	$capturador->fallidos = ['buzon@example.test'];
	$run = new \ReflectionMethod($envio, 'run');
	$run->invoke($envio, ['autor' => $luis->getUID(), 'sala' => $base['sala_nombre'], 'sala_tipo' => 'uno_a_uno']);
	$comprobar(str_contains($capturador->mensajes[1]->getPlainBody(), 'Luis Rojas'), 'los trabajos antiguos sin ficha también identifican al autor');
	$comprobar(end($logger->registros)[0] === 'error', 'se registra un destinatario fallido devuelto por el servicio de correo');
	$grande = $enriquecido;
	$grande['alerta_id'] .= '-grande';
	$grande['autor_ficha']['grupos'] = array_fill(0, 1000, str_repeat('Grupo institucional ficticio ', 5));
	$grande['participantes_fichas'] = array_fill(0, 100, $ficha);
	$grande['num_participantes'] = 100;
	$alertas->emitir($grande);
	foreach ($cola->getJobs(EnviarCorreoAlerta::class, null, 0) as $j) {
		if (($j->getArgument()['alerta_id'] ?? '') === $grande['alerta_id']) {
			$argumentos[] = $j->getArgument();
			$comprobar(strlen(json_encode($j->getArgument())) < 32000, 'una ficha con muchos grupos y participantes no supera el límite de la cola');
		}
	}
} catch (\Throwable $e) {
	$fallo = true;
	fwrite(STDERR, 'FALLA: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
	foreach ($argumentos as $arg) { $cola->remove(EnviarCorreoAlerta::class, $arg); }
	foreach ($original as $clave => $valor) { $config->setValueString('gsi_talk_guard', $clave, $valor); }
	foreach ($creados as $u) { $u->delete(); }
	if (is_file($archivo)) { unlink($archivo); }
}
echo "Resultado: $ok correctas, " . (int)$fallo . " fallas.\n";
exit($fallo ? 1 : 0);
