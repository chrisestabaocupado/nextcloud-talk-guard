<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReglasExportar extends Command {
	public function __construct(
		private Settings $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:reglas:exportar')
			->setDescription('Imprime las reglas activas en JSON (para editarlas e importarlas)')
			->addOption('predeterminadas', null, InputOption::VALUE_NONE, 'Imprimir las reglas que trae la app');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$json = $input->getOption('predeterminadas')
			? Settings::reglasPredeterminadas()
			: ($this->settings->reglasPersonalizadas() ?? Settings::reglasPredeterminadas());
		$output->writeln($json, OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
