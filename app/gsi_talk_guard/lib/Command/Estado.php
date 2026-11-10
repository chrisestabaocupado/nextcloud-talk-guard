<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\Evidencias;
use OCA\GsiTalkGuard\Service\MotorReglas;
use OCA\GsiTalkGuard\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Estado extends Command {
	public function __construct(
		private Settings $settings,
		private MotorReglas $motor,
		private Evidencias $evidencias,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:estado')
			->setDescription('Muestra la configuración y las reglas activas');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$piloto = $this->settings->usuariosPiloto();
		$output->writeln('<info>GSI Talk Guard</info>');
		$output->writeln('  Modo:                 ' . $this->settings->modo());
		$output->writeln('  Usuarios del piloto:  ' . ($piloto === [] ? 'todos' : implode(', ', $piloto)));
		$output->writeln('  Clave pública:        ' . ($this->evidencias->disponible() ? 'configurada' : '<comment>NO configurada (no se enmascara ni se guardan evidencias)</comment>'));
		$output->writeln('  Avisar al usuario:    ' . ($this->settings->avisarUsuario() ? 'sí' : 'no'));
		$output->writeln('  Reglas:               ' . ($this->settings->reglasPersonalizadas() === null ? 'predeterminadas' : 'personalizadas'));
		$output->writeln('  Archivo de alertas:   ' . $this->settings->archivoAlertas());
		$correo = $this->settings->correoPara();
		$output->writeln('  Correo:               ' . ($correo === [] ? 'desactivado' : implode(', ', $correo) . ' (desde ' . $this->settings->severidadMinimaCorreo() . ')'));
		$output->writeln('');

		$tabla = new Table($output);
		$tabla->setHeaders(['Regla', 'Tipo', 'Severidad', 'Acción', 'Máscara']);
		foreach ($this->motor->reglas() as $r) {
			$tabla->addRow([$r->id, $r->tipo, $r->severidad, $r->accion, $r->mascara ?? '-']);
		}
		$tabla->render();
		return 0;
	}
}
