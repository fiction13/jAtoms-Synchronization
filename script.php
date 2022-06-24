<?php
/**
 * @package    jAtomS - Synchronization plugin
 * @version    __DEPLOY_VERSION__
 * @author     Atom-S - atom-s.com
 * @copyright  Copyright (c) 2017 - 2022 Atom-S LLC. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link       https://atom-s.com/
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;

class plgTaskAtomS_SynchronizationInstallerScript
{
	/**
	 * Runs right after any installation action.
	 *
	 * @param   string            $type    Type of PostFlight action. Possible values are:
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @throws  Exception
	 *
	 * @return  boolean True on success, false on failure.
	 *
	 * @since  2.14.2-dev
	 */
	function postflight($type, $parent)
	{
		// Enable plugin
		if ($type == 'install')
		{
			$this->enablePlugin($parent);
		}
		return true;
	}

	/**
	 * Enable plugin after installation.
	 *
	 * @param   InstallerAdapter  $parent  Parent object calling object.
	 *
	 * @since  2.14.2-dev
	 */
	protected function enablePlugin($parent)
	{
		// Prepare plugin object
		$plugin          = new stdClass();
		$plugin->type    = 'plugin';
		$plugin->element = $parent->getElement();
		$plugin->folder  = (string) $parent->getParent()->manifest->attributes()['group'];
		$plugin->enabled = 1;

		// Update record
		Factory::getDbo()->updateObject('#__extensions', $plugin, array('type', 'element', 'folder'));
	}
}