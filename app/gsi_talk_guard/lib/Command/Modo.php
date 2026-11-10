<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Modo extends Command {
	public function __construct(
		private Settings $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:modo')
			->setDescription('Cambia el modo: normal, solo_alertar (interruptor general) o desactivado')
			->addArgument('modo', InputArgument::REQUIRED, implode(' | ', Settings::MODOS));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$modo = (string)$input->getArgument('modo');
		if (!in_array($modo, Settings::MODOS, true)) {
			$output->writeln('<error>Modo inválido. Use: ' . implode(', ', Settings::MODOS) . '</error>');
			return 1;
		}
		$this->settings->cambiarModo($modo);
		$output->writeln('Modo actual: <info>' . $modo . '</info>');
		return 0;
	}
}
