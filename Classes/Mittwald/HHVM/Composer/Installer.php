<?php
/*
 * Copyright (c) 2014 Martin Helmich <m.helmich@mittwald.de>
 *                    Mittwald CM Service GmbH & Co.KG
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Mittwald\HHVM\Composer;

use Composer\IO\IOInterface;
use Composer\Script\CommandEvent;
use Symfony\Component\Yaml\Yaml;


/**
 * Installer script for making TYPO3 Flow and Neos installations compatible with HHVM.
 *
 * @author     Martin Helmich <m.helmich@mittwald.de>
 * @package    Mittwald\HHVM
 * @subpackage Composer
 */
class Installer {

	/**
	 * Path that this package is stored under.
	 * TODO: Can this be determined automatically?
	 */
	const PACKAGE_PATH = 'Packages/Application/Mittwald.HHVM';

	/**
	 * Event that should be called whenever "composer install" or "composer update" is called.
	 *
	 * @param CommandEvent $event The composer event.
	 * @return void
	 */
	static public function postInstall(CommandEvent $event) {
		$io = $event->getIO();

		$patches = [];

		// TODO: Is there any better way to determine which packages are installed?
		//       Maybe any composer APIs that can be accessed from within this event!?
		// TODO: Can we determine the version numbers automatically!?
		if (is_dir('Packages/Framework/TYPO3.Flow')) {
			$patches[] = 'flow-2.2.x.patch';
		}
		if (is_dir('Packages/Application/TYPO3.Neos')) {
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

	/**
	 * Adds some special entries to the Settings.yaml.
	 *
	 * This method checks if a Settings.yaml is already present (if not, one will be
	 * created) and adds the following configuration options:
	 *
	 *     TYPO3:
	 *       Flow:
	 *         core:
	 *           phpBinaryPathAndFilename: /usr/bin/hhvm  # Is determined automatically
	 *           subRequestPhpIniPathAndFilename: false   # HHVM does not have a php.ini
	 *
	 * @param IOInterface $io Composer's IO interface.
	 * @throws \Exception
	 */
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

	/**
	 * Applies some patches to various packages to account for HHVM bugs.
	 *
	 * @param IOInterface $io Composer's IO interface.
	 * @param array $patches A list of patch files that should be applied.
	 * @throws \Exception
	 */
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

					for ($i = 0; $i < $lcount; $i++) {
						if (preg_match(',(reversed|patching file|succeeded),i', $lines[$i]) {
							$io->write('    <info>' . $lines[$i] . '</info>');
							$io->write('    <info>' . $lines[++$i] . '</info>');
						} else if (preg_match(',(failed),i', $lines[$i]) {
							$io->write('    <warning>' . $lines[$i] . '</warning>');
						} else {
							$io->write('    <comment>' . $lines[$i] . '</comment>');
						}
					}
				} else {
					$lines = array_filter(explode("\n", $response));
					foreach ($lines as $line) {
						$io->write('    <comment>' . $line . '</comment>');
					}
					$io->write('  - <info>Successfully applied patch <comment>' . $patch . '</comment>.</info>');
				}
			} else {
				throw new \Exception('Could not execute "' . $command . '".');
			}
		}
	}

	/**
	 * Copies a HDF file to the global configuration directory.
	 *
	 * Copies the HDF file containing some default configuration for HHVM to the global
	 * Configuration directory and replaces some markers within this file.
	 *
	 * @param IOInterface $io Composer's IO interface.
	 * @throws \Exception
	 */
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

}
