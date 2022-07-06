<?php
/*
 * @package    jAtomS - Synchronization plugin
 * @version    1.0.1
 * @author     Atom-S - atom-s.com
 * @copyright  Copyright (c) 2017 - 2022 Atom-S LLC. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link       https://atom-s.com
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\Table;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;

class PlgTaskAtoms_Synchronization extends CMSPlugin implements SubscriberInterface
{
	use TaskPluginTrait;

	/**
	 * Affects constructor behavior.
	 *
	 * @var  boolean
	 *
	 * @since  1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Loads the database object.
	 *
	 * @var  JDatabaseDriver
	 *
	 * @since  1.0.0
	 */
	protected $db = null;

	/**
	 * @var string[]
	 *
	 * @since 1.0.0
	 */
	private const TASKS_MAP = [
		'atoms_synchronization.synchronization' => [
			'langConstPrefix' => 'PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION',
			'method'          => 'synchronization',
			'form'            => 'synchronization',
		],
	];

	/**
	 * @inheritDoc
	 *
	 * @return string[]
	 *
	 * @since 1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm'
		];
	}

	/**
	 * Method to synchronization AtomS Frame tours.
	 *
	 * @param   ExecuteTaskEvent  $event  Event data.
	 *
	 * @return int Status code.
	 *
	 * @since 1.0.0
	 */
	protected function synchronization(ExecuteTaskEvent $event): int
	{
		$params = $event->getArgument('params');

		if (empty($params->showcase_key))
		{
			return Status::NO_RUN;
		}

		// Remove memory limit
		$this->memoryRemoveLimit();

		$showcase = $params->showcase_key;

		$this->logTask(Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_START', $showcase));

		// Initialise component
		$componentPath = JPATH_ADMINISTRATOR . '/components/com_jatoms';
		JLoader::register('jAtomSHelper', $componentPath . '/helpers/jatoms.php');
		JLoader::register('jAtomSHelperPrice', $componentPath . '/helpers/price.php');
		JLoader::register('jAtomSHelperAPI', $componentPath . '/helpers/api.php');
		Table::addIncludePath($componentPath . '/tables');
		BaseDatabaseModel::addIncludePath($componentPath . '/models');
		Form::addFormPath($componentPath . '/models/forms');
		Factory::getLanguage()->load('com_jatoms', JPATH_ADMINISTRATOR);

		$error = false;

		try
		{
			// Get category
			$this->logTask(Text::_('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_CATEGORY_GET'));
			$db    = $this->db;
			$query = $db->getQuery(true)
				->select('id')
				->from($db->quoteName('#__jatoms_categories'))
				->where($db->quoteName('alias') . ' = ' . $db->quote($showcase));

			// Create category
			if (!$category = $db->setQuery($query)->loadResult())
			{
				$this->logTask(Text::_('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_CATEGORY_CREATE'));

				/* @var jAtomSModelCategory $categoryModel */
				$categoryModel = BaseDatabaseModel::getInstance('Category', 'jAtomSModel',
					array('ignore_request' => true));

				$data = array(
					'id'        => 0,
					'show'      => 0,
					'state'     => 1,
					'title'     => Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_CATEGORY_TITLE', $showcase),
					'alias'     => $showcase,
					'parent_id' => 1,
					'params'    => array(
						'tours_show'         => 0,
						'seo_category_title' => 'COM_JATOMS_TOURS',
						'seo_category_h1'    => 'COM_JATOMS_TOURS',
					)
				);

				if (!$category = (int) $categoryModel->save($data))
				{
					$message = [];
					foreach ($categoryModel->getErrors() as $error)
					{
						$message[] = ($error instanceof Exception) ? $error->getMessage() : $error;
					}

					$this->logTask(Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_ERROR_CATEGORY', implode(', ', $message)));
				}
			}

			// Get exist
			$this->logTask(Text::_('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_EXIST_GET'));
			$query  = $db->getQuery(true)
				->select('tour_id')
				->from($db->quoteName('#__jatoms_tours_categories'))
				->where('category_id = ' . $category);
			$exists = $db->setQuery($query)->loadColumn();
			if (!empty($exists)) $exists = ArrayHelper::toInteger($exists);

			// Get tours
			$this->logTask(Text::_('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_TOURS_GET'));
			$data  = array(
				'offset'   => 0,
				'limit'    => 99999999,
				'showcase' => $showcase,
			);
			$tours = array();
			$api   = jAtomSHelperApi::sendRequest('getTours', $data);
			foreach ($api->get('tours', array()) as $tour) $tours[] = $tour->id;
			if (!empty($tours)) $tours = ArrayHelper::toInteger($tours);

			// Synchronize tours
			$this->logTask(Text::_('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_TOURS_SYNCHRONIZATION'));

			foreach ($tours as $id)
			{
				try
				{
					$data = array('id' => $id, 'showcase' => $showcase);
					$api  = jAtomSHelperApi::sendRequest('getTour', $data);

					if (!$tour = $api->get('tour', false))
					{
						$this->logTask(Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_ERROR_TOUR_NOT_FOUND', $id));
					}

					/* @var jAtomSModelSynchronization $synchronizationModel */
					$synchronizationModel = BaseDatabaseModel::getInstance('Synchronization', 'jAtomSModel',
						array('ignore_request' => true));
					if (!$data = $synchronizationModel->prepareTourData($tour))
					{
						$msg = array();
						foreach ($synchronizationModel->getErrors() as $error)
						{
							$msg[] = ($error instanceof Exception) ? $error->getMessage() : $error;
						}

						$this->logTask(Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_ERROR_TOUR', implode(', ', $msg)));
					}

					/* @var jAtomSModelTour $tourModel */
					$tourModel = BaseDatabaseModel::getInstance('Tour', 'jAtomSModel',
						array('ignore_request' => true));
					if (!$tourModel->save($data))
					{
						$message = array();
						foreach ($synchronizationModel->getErrors() as $error)
						{
							$message[] = ($error instanceof Exception) ? $error->getMessage() : $error;
						}

						$this->logTask(Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_ERROR_TOUR_SAVE', implode(', ', $message)));
					}
				}
				catch (Exception $e)
				{
					$this->logTask($e->getMessage(), 'error');

					$error = true;
				}
			}

			// Check error
			if ($error)
			{
				return Status::KNOCKOUT;
			}

			// Set mapping
			$all = array_unique(array_merge($exists, $tours));
			foreach ($all as $i => $id)
			{
				if (in_array($id, $exists) && in_array($id, $tours)) continue;

				$query = $db->getQuery(true)
					->select(array('id', 'additional_categories'))
					->from($db->quoteName('#__jatoms_tours'))
					->where('id = ' . (int) $id);

				$update                        = $db->setQuery($query)->loadObject();
				$update->additional_categories = ArrayHelper::toInteger(explode(',', $update->additional_categories));

				if (!in_array($id, $tours))
				{
					$update->additional_categories = array_diff($update->additional_categories, array($category));

					$query = $db->getQuery(true)
						->delete($db->quoteName('#__jatoms_tours_categories'))
						->where('tour_id = ' . $id)
						->where('category_id = ' . $category);
					$db->setQuery($query)->execute();
				}
				elseif (!in_array($id, $exists))
				{
					$update->additional_categories[] = $category;

					$insert              = new stdClass();
					$insert->tour_id     = $id;
					$insert->category_id = $category;
					$db->insertObject('#__jatoms_tours_categories', $insert);
				}

				$update->additional_categories = implode(',', array_unique($update->additional_categories));
				$db->updateObject('#__jatoms_tours', $update, 'id');
			}
		}
		catch (Exception $e)
		{
			$this->logTask($e->getMessage(), 'error');

			$error = true;
		}

		// Check error
		if ($error)
		{
			return Status::KNOCKOUT;
		}

		$this->logTask(Text::sprintf('PLG_TASK_ATOMS_SYNCHRONIZATION_TASKS_TASK_SYNCHRONIZATION_LOGS_END', $showcase));

		return Status::OK;
	}

	/**
	 * Method for override the memory limit set by the PHP INI.
	 *
	 * @return integer  The routine exit code.
	 *
	 * @throws Exception
	 *
	 * @since 1.0.0
	 */
	private function memoryRemoveLimit(): bool
	{
		$success = false;

		if (function_exists('ini_set'))
		{
			$success = ini_set('memory_limit', -1) !== false;
		}

		$this->logTask('Memory limit override ' . $success ? 'successful' : 'failed');

		return true;
	}
}