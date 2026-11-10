<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\Hallazgo;
use OCA\GsiTalkGuard\Service\MotorReglas;
use OCA\GsiTalkGuard\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Probar extends Command {
	public function __construct(
		private MotorReglas $motor,
		private Settings $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:probar')
			->setDescription('Prueba las reglas con un texto, sin enviar nada a Talk')
			->addArgument('texto', InputArgument::REQUIRED, 'Texto a analizar');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$texto = (string)$input->getArgument('texto');
		$hallazgos = $this->motor->evaluar($texto);
		if ($hallazgos === []) {
			$output->writeln('Sin coincidencias.');
			return 0;
		}
		foreach ($hallazgos as $h) {
			$output->writeln(sprintf('[%-7s] %s (%s): %s', mb_strtoupper($h->regla->severidad),
				$h->regla->id, $h->regla->accion, json_encode($h->fragmentos(), JSON_UNESCAPED_UNICODE)));
		}
		$reglas = array_map(fn (Hallazgo $h) => $h->regla, $hallazgos);
		$enmascarado = $this->motor->enmascarar($texto, $reglas);
		if ($enmascarado !== $texto) {
			$aplica = $this->settings->modo() === Settings::MODO_NORMAL;
			$output->writeln('');
			$output->writeln('Texto que verían los participantes' . ($aplica ? ':' : ' (no se aplica en modo ' . $this->settings->modo() . '):'));
			$output->writeln('  ' . $enmascarado);
		}
		return 0;
	}
}
