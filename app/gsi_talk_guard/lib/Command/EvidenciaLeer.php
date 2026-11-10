<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\Alertas;
use OCA\GsiTalkGuard\Service\Evidencias;
use OCA\GsiTalkGuard\Service\ContextoAlertas;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class EvidenciaLeer extends Command {
	public function __construct(
		private Evidencias $evidencias,
		private Alertas $alertas,
		private ContextoAlertas $contexto,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:evidencia:leer')
			->setDescription('Descifra la evidencia de una alerta (la consulta queda registrada)')
			->addArgument('evidencia', InputArgument::REQUIRED, 'Valor del campo "evidencia" de la alerta')
			->addOption('caso', null, InputOption::VALUE_REQUIRED, 'Correlativo del caso que autoriza la lectura')
			->addOption('solicitante', null, InputOption::VALUE_REQUIRED, 'Persona que solicita la lectura')
			->addOption('archivo-clave', null, InputOption::VALUE_REQUIRED, 'Archivo con la clave privada (si no, se pide en pantalla)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$caso = trim((string)$input->getOption('caso'));
		$solicitante = trim((string)$input->getOption('solicitante'));
		if ($caso === '' || $solicitante === '') {
			$output->writeln('<error>Indique --caso y --solicitante: toda lectura de evidencias queda registrada.</error>');
			return 1;
		}
		$archivo = (string)$input->getOption('archivo-clave');
		if ($archivo !== '') {
			$clave = is_readable($archivo) ? (string)file_get_contents($archivo) : '';
		} else {
			$pregunta = (new Question('Clave privada de evidencias: '))->setHidden(true)->setHiddenFallback(false);
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');
			$clave = (string)$helper->ask($input, $output, $pregunta);
		}

		$id = (string)$input->getArgument('evidencia');
		$resultado = 'fallida';
		try {
			$e = $this->evidencias->leer($id, $clave, $caso, $solicitante);
			$resultado = 'leida';
		} catch (\Throwable $ex) {
			$output->writeln('<error>' . $ex->getMessage() . '</error>');
			return 1;
		} finally {
			// La consulta también genera una alerta local.
			$this->alertas->emitir([
				'alerta_id' => 'acceso-' . $id . '-' . time(),
				'evento' => 'acceso_evidencia',
				'regla_id' => 'acceso_evidencia',
				'regla' => 'Consulta de evidencia cifrada',
				'severidad' => 'media',
				'accion' => $resultado,
				'evidencia' => $id,
				'caso' => $caso,
				'solicitante' => $solicitante,
			]);
		}

		$output->writeln('Evidencia:   ' . $e['alerta_id']);
		$output->writeln('Mensaje:     ' . $e['mensaje_id'] . ' en la sala ' . $e['sala_token']);
		$ficha = $this->contexto->persona($e['autor']);
		$output->writeln('Autor:       ' . $ficha['nombre'] . ' (' . ($ficha['usuario'] ?: $ficha['id']) . ')', OutputInterface::OUTPUT_RAW);
		$output->writeln('Correo:      ' . ($ficha['correo'] ?: 'no disponible'), OutputInterface::OUTPUT_RAW);
		$output->writeln('ID interno:  ' . $e['autor'], OutputInterface::OUTPUT_RAW);
		$output->writeln('Guardada:    ' . gmdate('c', $e['creado']));
		$output->writeln('ID de clave: ' . $e['clave_id']);
		$output->writeln('Integridad:  ' . ($e['integra'] ? 'íntegra (SHA-256 coincide)' : '<error>NO COINCIDE</error>'));
		$output->writeln('---');
		$output->writeln($e['texto'], OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
