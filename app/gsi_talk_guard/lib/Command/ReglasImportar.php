<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\MotorReglas;
use OCA\GsiTalkGuard\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReglasImportar extends Command {
	public function __construct(
		private Settings $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:reglas:importar')
			->setDescription('Valida e instala un archivo de reglas JSON')
			->addArgument('archivo', InputArgument::OPTIONAL, 'Ruta del archivo JSON')
			->addOption('predeterminadas', null, InputOption::VALUE_NONE, 'Volver a las reglas que trae la app');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('predeterminadas')) {
			$this->settings->guardarReglas('');
			$output->writeln('Se restablecieron las reglas predeterminadas.');
			return 0;
		}
		$ruta = (string)$input->getArgument('archivo');
		if ($ruta === '' || !is_readable($ruta)) {
			$output->writeln('<error>Indique un archivo JSON legible.</error>');
			return 1;
		}
		$json = (string)file_get_contents($ruta);
		try {
			$datos = MotorReglas::interpretar($json);
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>Reglas rechazadas: ' . $e->getMessage() . '</error>');
			return 1;
		}
		$this->settings->guardarReglas($json);
		$enmascaran = array_filter($datos['reglas'], fn ($r) => $r->puedeEnmascarar());
		$output->writeln(sprintf('Reglas instaladas: %d (%d enmascaran).', count($datos['reglas']), count($enmascaran)));
		return 0;
	}
}
