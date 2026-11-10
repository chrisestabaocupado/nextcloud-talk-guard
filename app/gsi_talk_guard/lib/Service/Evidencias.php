<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Evidencias cifradas con "sealed box" de libsodium.
 * El servidor solo tiene la clave PÚBLICA: puede cifrar, pero no descifrar.
 * La clave privada la custodia la GSI fuera del servidor y solo se usa,
 * de forma puntual, al leer una evidencia dentro de un caso autorizado.
 */
class Evidencias {
	public const TABLA = 'gsitg_evidencias';
	public const TABLA_ACCESOS = 'gsitg_accesos';

	public function __construct(
		private IDBConnection $db,
		private Settings $settings,
	) {
	}

	public function disponible(): bool {
		return $this->settings->clavePublica() !== null && function_exists('sodium_crypto_box_seal');
	}

	/** @return string clave privada en base64 (se muestra una sola vez) */
	public function generarClaves(): string {
		$par = sodium_crypto_box_keypair();
		$this->settings->guardarClavePublica(base64_encode(sodium_crypto_box_publickey($par)));
		$privada = base64_encode(sodium_crypto_box_secretkey($par));
		sodium_memzero($par);
		return $privada;
	}

	public function guardar(string $alertaId, int $mensajeId, string $salaToken, string $autor, string $texto): bool {
		$publica = $this->settings->clavePublica();
		if ($publica === null) {
			return false;
		}
		$cifrado = sodium_crypto_box_seal($texto, base64_decode($publica, true) ?: '');
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLA)->values([
			'alerta_id' => $qb->createNamedParameter($alertaId),
			'mensaje_id' => $qb->createNamedParameter($mensajeId, IQueryBuilder::PARAM_INT),
			'sala_token' => $qb->createNamedParameter($salaToken),
			'autor' => $qb->createNamedParameter($autor),
			'huella' => $qb->createNamedParameter(hash('sha256', $texto)),
			'contenido' => $qb->createNamedParameter(base64_encode($cifrado)),
			'creado' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return true;
	}

	/**
	 * Descifra una evidencia. Siempre registra el intento, aunque falle.
	 *
	 * @return array{alerta_id: string, mensaje_id: int, sala_token: string, autor: string, creado: int, texto: string, integra: bool}
	 */
	public function leer(string $alertaId, string $privadaBase64, string $caso, string $solicitante): array {
		$resultado = 'error';
		try {
			$qb = $this->db->getQueryBuilder();
			$fila = $qb->select('*')->from(self::TABLA)
				->where($qb->expr()->eq('alerta_id', $qb->createNamedParameter($alertaId)))
				->setMaxResults(1)
				->executeQuery()->fetch();
			if ($fila === false) {
				$resultado = 'no_encontrada';
				throw new \RuntimeException("No hay evidencia para la alerta $alertaId.");
			}
			$privada = base64_decode(trim($privadaBase64), true);
			$publica = base64_decode((string)$this->settings->clavePublica(), true);
			if ($privada === false || strlen($privada) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES || $publica === false) {
				$resultado = 'clave_invalida';
				throw new \RuntimeException('La clave privada no tiene el formato correcto.');
			}
			$par = sodium_crypto_box_keypair_from_secretkey_and_publickey($privada, $publica);
			$texto = sodium_crypto_box_seal_open(base64_decode((string)$fila['contenido']), $par);
			sodium_memzero($par);
			if ($texto === false) {
				$resultado = 'clave_incorrecta';
				throw new \RuntimeException('No se pudo descifrar: la clave privada no corresponde.');
			}
			$resultado = 'leida';
			return [
				'alerta_id' => (string)$fila['alerta_id'],
				'mensaje_id' => (int)$fila['mensaje_id'],
				'sala_token' => (string)$fila['sala_token'],
				'autor' => (string)$fila['autor'],
				'creado' => (int)$fila['creado'],
				'texto' => $texto,
				'integra' => hash_equals((string)$fila['huella'], hash('sha256', $texto)),
			];
		} finally {
			$this->registrarAcceso($alertaId, $caso, $solicitante, $resultado);
		}
	}

	private function registrarAcceso(string $alertaId, string $caso, string $solicitante, string $resultado): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLA_ACCESOS)->values([
			'alerta_id' => $qb->createNamedParameter($alertaId),
			'caso' => $qb->createNamedParameter($caso),
			'solicitante' => $qb->createNamedParameter($solicitante),
			'resultado' => $qb->createNamedParameter($resultado),
			'fecha' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}
}
