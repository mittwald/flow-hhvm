<?php
namespace Mittwald\HHVM\Composer;

use Composer\IO\IOInterface;
use Composer\Script\CommandEvent;

class Installer {

	const PACKAGE_PATH = 'Packages/Application/Mittwald.HHVM';

	static public function postInstall(CommandEvent $event) {
		$io = $event->getIO();

		$patches = ['flow-2.2.x.patch'];

		if (is_dir('Packages/Applications/TYPO3.Neos')) {
			$patches[] = 'neos-1.0.x.patch';
		}

		try {
			self::applyPatches($io, $patches);
			self::copyHhvmConfigFile($io);
		} catch (\Exception $exception) {
			$io->write('<error>' . $exception->getMessage() . '</error>');
			return;
		}

		$io->write([
			'',
			'Your TYPO3 Flow/Neos installation is now ready for usage with HHVM.',
			'To start HHVM (port 9000), use the following command:',
			'    <comment>hhvm -m server -c Configuration/HipHopJit.hdf</comment>'
		]);
	}

	static private function copyHhvmConfigFile(IOInterface $io) {
		$templatePath = sprintf('%s/Configuration/HipHopJit.hdf', self::PACKAGE_PATH);
		if (!file_exists($templatePath)) {
			throw new \Exception('The file "' . $templatePath . '" does not exist!');
		}

		$contents = file_get_contents($templatePath);
		$contents = str_replace('###PATH###', getcwd(), $contents);

		file_put_contents('Configuration/HipHopJit.hdf', $contents);
		$io->write('<comment>Created "Configuration/HipHopJit.hdf"</comment>');
	}

	static private function applyPatches(IOInterface $io, array $patches) {
		$patchBinary = exec('which patch', $output, $result);
		if ($result !== 0 || !$patchBinary) {
			throw new \Exception(
				'Could not find a "patch" binary on your system. Please make sure ' .
				'that your PATH variable contains the path of your "patch" binary'
			);
		}

		$io->write('<info>Found "patch" binary at "' . $patchBinary . '"</info>');
		$io->write('<info>Applying HHVM patches.</info>');

		foreach ($patches as $patch) {
			$io->write('  - Applying <comment>' . $patch . '</comment>.');

			$command = sprintf('%s -p1 -N -t -i %s/Build/Patches/%s', $patchBinary, self::PACKAGE_PATH, $patch);
			$process = popen($command, 'r');
			if (is_resource($process)) {
				$response = stream_get_contents($process);
				$exitCode = pclose($process);

				if ($exitCode !== 0) {
					$io->write('<warning>' . $response . '</warning>');
					throw new \Exception(
						'The command "' . $command . '" failed with exit code ' . $exitCode . '.'
					);
				} else {
					$io->write('<info>' . $response . '</info>');
					$io->write('<comment>Successfully applied patch "' . $patch . '".</comment>');
				}
			} else {
				throw new \Exception('Could not execute "' . $command . '".');
			}
		}
	}

}