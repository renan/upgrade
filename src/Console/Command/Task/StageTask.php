<?php
/**
 * Upgrade stage task
 *
 * Handles staging changes for the upgrade process
 *
 * PHP 5
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 3.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Upgrade\Console\Command\Task;

use Cake\Console\Shell;
use Cake\Utility\Debugger;
use Cake\Utility\File;
use Cake\Utility\Folder;

/**
 * Base class for Bake Tasks.
 *
 */
class StageTask extends Shell {

/**
 * Files
 *
 * @var array
 */
	protected $_files = [];

/**
 * Paths
 *
 * @var array
 */
	protected $_paths = [];

/**
 * Staged changes for processing at the end
 *
 * @var array
 */
	protected $_staged = [
		'change' => [],
		'delete' => [],
		'move' => []
	];

/**
 * Write staged changes
 *
 * If it's a dry run though - only show what will be done, don't do anything
 *
 * @param string $path file path
 * @return void
 */
	public function commit($path = null) {
		if (!$path) {
			foreach (array_keys($this->_staged['change']) as $path) {
				$this->commit($path);
			}

			foreach ($this->_staged['move'] as $path => $to) {
				if (isset($this->_staged['change'][$path])) {
					continue;
				}
				$this->commit($path);
			}

			foreach ($this->_staged['delete'] as $path) {
				$this->commit($path);
			}

			$Folder = new Folder(TMP . 'upgrade/');
			$Folder->delete();
			return;
		}

		$dryRun = !empty($this->params['dryRun']);
		$isMove = isset($this->_staged['move'][$path]);
		$isChanged = (isset($this->_staged['change'][$path]) && count($this->_staged['change'][$path]) > 1);
		$isDelete = in_array($path, $this->_staged['delete']);

		if (!$isMove && !$isChanged && !$isDelete) {
			return;
		}

		$gitCd = sprintf('cd %s; ', escapeshellarg(dirname($path)));

		if ($isDelete) {
			$this->out(
				__d(
					'cake_console',
					'<info>Delete %s</info>',
					Debugger::trimPath($path)
				)
			);
			if ($dryRun) {
				return true;
			}

			if (!empty($this->params['git'])) {
				exec($gitCd . sprintf('git rm -f %s', escapeshellarg($path)));
				return;
			}

			if (is_dir($path)) {
				$Folder = new Folder($path);
				return $Folder->delete();
			}

			$File = new File($to, true);
			return $File->delete();
		}

		if ($isMove && !$isChanged) {
			$to = $this->_staged['move'][$path];
			$this->out(
				__d(
					'cake_console',
					'<info>Move %s to %s</info>',
					Debugger::trimPath($path),
					Debugger::trimPath($to)
				)
			);
			if ($dryRun || !file_exists($path)) {
				return true;
			}

			if (!empty($this->params['git'])) {
				exec($gitCd . 'git mv -f ' . escapeshellarg($path) . ' ' . escapeshellarg($path . '__'));
				exec($gitCd . 'git mv -f ' . escapeshellarg($path . '__') . ' ' . escapeshellarg($to));
				return;
			}

			if (is_dir($path)) {
				$Folder = new Folder($path);
				return $Folder->move($to);
			}

			$File = new File($to, true);
			return ($File->write(file_get_contents($path)) && unlink($path));
		}

		$start = reset($this->_staged['change'][$path]);
		end($this->_staged['change'][$path]);
		$final = end($this->_staged['change'][$path]);

		$oPath = TMP . 'upgrade/' . $start;
		$uPath = TMP . 'upgrade/' . $final;

		exec("git diff --no-index '$oPath' '$uPath'", $output);

		$output = implode($output, "\n");
		$i = strrpos($output, $final);
		$diff = substr($output, ($i + 41));

		if ($isMove) {
			$to = $this->_staged['move'][$path];
			$this->out(
				__d(
					'cake_console',
					'<info>Move %s to %s and update</info>',
					Debugger::trimPath($path),
					Debugger::trimPath($to)
				)
			);
		} else {
			$this->out(__d('cake_console', '<info>Update %s</info>', Debugger::trimPath($path)));
		}
		$this->out($diff, 1, $dryRun ? Shell::NORMAL : SHELL::VERBOSE);

		if ($dryRun) {
			return true;
		}

		if ($isMove) {
			if ($this->params['git']) {
				exec($gitCd . 'git mv -f ' . escapeshellarg($path) . ' ' . escapeshellarg($path . '__'));
				exec($gitCd . 'git mv -f ' . escapeshellarg($path . '__') . ' ' . escapeshellarg($to));
			} else {
				unlink($path);
			}
		}

		$File = new File($path, true);
		return $File->write(file_get_contents($uPath));
	}

/**
 * delete
 *
 * @param string $path
 * @return boolean
 */
	public function delete($path) {
		$this->_staged['delete'][] = $path;
		return true;
	}

/**
 * move
 *
 * @param string $from
 * @param string $to
 * @return boolean
 */
	public function move($from, $to) {
		if (is_dir($from)) {
			$this->_findFiles('.*');
			foreach ($this->_files as $fromFile) {
				$newFile = str_replace($from, $new, $fromFile);
				if ($newFile !== $fromFile) {
					$this->_staged['move'][$fromFile] = $newFile;
				}
			}
			$this->delete($from);
			return true;
		}

		$this->_staged['move'][$from] = $to;
		return true;
	}

/**
 * Store a change for a file
 *
 * @params string $filePath (unused, for future reference)
 * @param string $original
 * @param string $updated
 * @return boolean
 */
	public function change($filePath, $original, $updated) {
		if ($original === $updated) {
			return false;
		}

		$oHash = sha1($original);
		if (empty($this->_staged['change'][$filePath])) {
			$this->_staged['change'][$filePath][] = $oHash;
			$o = new File(TMP . 'upgrade/' . $oHash, true);
			$oPath = $o->path;
			$o->write($original);
		} else {
			$oHash = reset($this->_staged['change'][$filePath]);
		}

		$uHash = sha1($updated);
		if ($uHash === end($this->_staged['change'][$filePath])) {
			return false;
		}

		$u = new File(TMP . 'upgrade/' . $uHash, true);
		$uPath = $u->path;
		$u->write($updated);

		$this->_staged['change'][$filePath][] = $uHash;
		return true;
	}

/**
 * Get the source of a file, taking account that there may be incremental diffs
 *
 * @param string $path
 * @return string
 */
	public function source($path) {
		if (isset($this->_staged['change'][$path])) {
			$path = TMP . 'upgrade/' . end($this->_staged['change'][$path]);
		}

		return file_get_contents($path);
	}

/**
 * Searches the paths and finds files based on extension.
 *
 * @param array $excludes
 * @param boolean $reset
 * @return array
 */
	public function files($excludes = [], $reset = false) {
		if ($reset) {
			$this->_files = [];
		}

		if (!$this->_files) {
			if (!$this->_paths) {
				$this->_paths = [$this->_getPath()];
			}

			foreach ($excludes as &$exclude) {
				$exclude = preg_quote($exclude);
			}
			$excludePattern = '@[\\/](' . implode($excludes, '|') . ')([\\/]|$)@';

			foreach ($this->_paths as $path) {
				if (!is_dir($path)) {
					if (is_file($path)) {
						$this->_files[] = $path;
					}
					continue;
				}
				$Iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($path)
				);
				foreach ($Iterator as $file) {
					$path = $file->getPathname();
					if (!$file->isFile() || preg_match($excludePattern, $path)) {
						continue;
					}
					$this->_files[] = $path;
				}
			}
		}

		return $this->_files;
	}

/**
 * Get the path to operate on. Uses either the first argument,
 * or the plugin parameter if its set.
 *
 * @return string
 */
	protected function _getPath() {
		if (count($this->args) === 1) {
			return realpath($this->args[0]);
		}

		return realpath($this->args[1]);
	}

}
