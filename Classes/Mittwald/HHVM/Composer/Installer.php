<?php
namespace Mittwald\HHVM\Composer;

use Composer\IO\IOInterface;
use Composer\Script\CommandEvent;
use Symfony\Component\Yaml\Yaml;

class Installer {

	const PACKAGE_PATH = 'Packages/Application/Mittwald.HHVM';

	static public function postInstall(CommandEvent $event) {
		$io = $event->getIO();

		$patches = ['flow-2.2.x.patch'];

		if (is_dir('Packages/Applications/TYPO3.Neos')) {
			$patches[] = 'neos-1.0.x.patch';
		}

		try {
			self::fixConfigurationFile($io);
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
			'',
			'    <comment>hhvm -m server -c Configuration/HipHopJit.hdf</comment>'
		]);
	}

	static private function fixConfigurationFile(IOInterface $io) {
		$configurationFile = 'Configuration/Settings.yaml';

		$io->write('<info>Fixing configuration file.</info>');

		if (file_exists($configurationFile)) {
			$io->write('  - Configuration file <comment>' . $configurationFile . '</comment> already exists.');

			$content = file_get_contents($configurationFile);
			$config = Yaml::parse($content);

			$io->write('  - Parsed <comment>' . $configurationFile . '</comment>.');
		} else {
			$io->write('  - Configuration file <comment>' . $configurationFile . '</comment> does not yet exist.');
			$config = [];
		}

		$hhvmBinary = exec('which hhvm', $output, $result);
		if ($result !== 0 || !$hhvmBinary) {
			throw new \Exception(
				'Could not find the "hhvm" binary on your system. Please make sure ' .
				'that you have HHVM installed and your PATH contains the "hhvm" binary.'
			);
		}

		$io->write('  - Found HHVM at <comment>' . $hhvmBinary . '</comment>');

		if (
			isset($config['TYPO3']['Flow']['core']['phpBinaryPathAndFilename']) &&
			$config['TYPO3']['Flow']['core']['phpBinaryPathAndFilename'] === $hhvmBinary &&
			isset($config['TYPO3']['Flow']['core']['subRequestPhpIniPathAndFilename']) &&
			$config['TYPO3']['Flow']['core']['subRequestPhpIniPathAndFilename'] === FALSE
		) {
			$io->write('  - No further action necessary.');
			return;
		}

		$config['TYPO3']['Flow']['core']['phpBinaryPathAndFilename'] = $hhvmBinary;
		$config['TYPO3']['Flow']['core']['subRequestPhpIniPathAndFilename'] = FALSE;

		$io->write('  - Set <comment>TYPO3.Flow.core.phpBinaryPathAndFilename</comment> to <comment>' . $hhvmBinary . '</comment>');
		$io->write('  - Set <comment>TYPO3.Flow.core.subRequestPhpIniPathAndFilename</comment> to <comment>false</comment>');

		file_put_contents($configurationFile, Yaml::dump($config, 99, 2));
		$io->write('  - Wrote <comment>' . $configurationFile . '</comment>.');
	}

	static private function copyHhvmConfigFile(IOInterface $io) {
		$templatePath = sprintf('%s/Configuration/HipHopJit.hdf', self::PACKAGE_PATH);
		if (!file_exists($templatePath)) {
			throw new \Exception('The file "' . $templatePath . '" does not exist!');
		}

		$contents = file_get_contents($templatePath);
		$contents = str_replace('###PATH###', getcwd(), $contents);

		file_put_contents('Configuration/HipHopJit.hdf', $contents);
		$io->write('<info>Created <comment>Configuration/HipHopJit.hdf</comment>.</info>');
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

			$command = sprintf('%s -s -p1 -N -t -i %s/Build/Patches/%s', $patchBinary, self::PACKAGE_PATH, $patch);
			$process = popen($command, 'r');
			if (is_resource($process)) {
				$response = stream_get_contents($process);
				$exitCode = pclose($process);

				if ($exitCode !== 0) {
					$io->write([
						'',
						'<warning>The patch "' . $patch . '" could not be applied cleanly.' . PHP_EOL .
						'This is not necessarily something bad, since it is also possible that the' . PHP_EOL .
						'patch has already been applied. Nevertheless, here\'s the output of patch,' . PHP_EOL .
						'please make sure that nothing is wrong:</warning>',
						''
					]);

					$lines = explode("\n", $response);
					$lcount = count($lines);

					for ($i = 0; $i < $lcount; $i ++) {
						if (strpos(strtolower($lines[$i]), 'reversed') !== FALSE) {
							$io->write('    <info>' . $lines[$i] . '</info>');
							$io->write('    <info>' . $lines[++$i] . '</info>');
						} else {
							$io->write('    <comment>' . $lines[$i] . '</comment>');
						}
					}
				} else {
					$lines = explode("\n", $response);
					foreach ($lines as $line) {
						$io->write('    <comment>' . $line . '</comment>');
					}
					$io->write('<info>Successfully applied patch "' . $patch . '".</info>');
				}
			} else {
				throw new \Exception('Could not execute "' . $command . '".');
			}
		}
	}

}