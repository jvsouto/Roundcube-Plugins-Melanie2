<?php
/**
 * Melanie2 driver for the Tasklist plugin
 *
 * @version @package_version@
 */

@include_once 'includes/libm2.php';

/**
 * Classe Melanie2 Driver
 * Permet de gérer les taches Melanie2 depuis Roundcube
 * @author Thomas Payen <thomas.payen@i-carre.net> PNE Annuaire et Messagerie/MEDDE
 */
class tasklist_melanie2_driver extends tasklist_driver
{
    const DB_DATE_FORMAT = 'Y-m-d H:i:s';
    const SHORT_DB_DATE_FORMAT = 'Y-m-d';

    // features supported by the backend
    public $alarms = false;
    public $attachments = false;
    public $undelete = false; // task undelete action
    public $alarm_types = array('DISPLAY');
    public $sortable = false;

    private $rc;
    private $plugin;
    /**
     * Tableau de listes de taches Melanie2
     * @var LibMelanie\Api\Melanie2\Taskslist []
     */
    private $lists;
    private $folders = array();
    private $tasks = array();

    // Melanie2
    /**
     * Utilisateur Melanie2
     * @var LibMelanie\Api\Melanie2\User
     */
     private $user;


    /**
     * Default constructor
     */
    public function __construct($plugin)
    {
        $this->rc = $plugin->rc;
        $this->plugin = $plugin;

        // User Melanie2
        if (!empty($this->rc->user->ID)) {
            $this->user = new LibMelanie\Api\Melanie2\User();
            $this->user->uid = $this->rc->user->get_username();
        }

        $this->_read_lists();
    }

    /**
     * Génération d'un code couleur aléatoire
     * Utilisé pour générer une premiere couleur pour les agendas si aucune n'est positionnée
     * @return string Code couleur
     * @private
     */
    private function _random_color()
    {
        mt_srand((double)microtime()*1000000);
        $c = '';
        while(strlen($c)<6){
            $c .= sprintf("%02X", mt_rand(0, 255));
        }
        return $c;
    }

    /**
     * Read available calendars for the current user and store them internally
     */
    private function _read_lists($force = false)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        // already read sources
        if (isset($this->lists) && !$force)
            return $this->lists;

        if (isset($this->user)) {
            $this->lists = $this->user->getSharedTaskslists();
        }

        return $this->lists;
    }

    /**
     * Get a list of available task lists from this source
     */
    public function get_lists()
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::get_lists()");
        // Lecture des listes
        $this->_read_lists();

        // Récupération des préférences de l'utilisateur
        $hidden_tasklists = $this->rc->config->get('hidden_tasklists', array());
        $color_tasklists = $this->rc->config->get('color_tasklists', array());
        $active_tasklists = $this->rc->config->get('active_tasklists', null);
        $alarm_tasklists = $this->rc->config->get('alarm_tasklists', null);

        // attempt to create a default list for this user
        if (empty($this->lists)) {
            $infos = melanie2::get_user_infos($this->user->uid);
            if ($this->create_list(array('id' => $this->user->uid, 'name' => $infos['cn'][0], 'color' => $this->_random_color())))
                $pref = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
                $pref->scope = LibMelanie\Config\ConfigMelanie::TASKSLIST_PREF_SCOPE;
                $pref->name = LibMelanie\Config\ConfigMelanie::TASKSLIST_PREF_DEFAULT_NAME;
                $pref->value = $this->user->uid;
                $pref->save();
                $this->_read_lists(true);
        }
        $default_tasklist = $this->user->getDefaultTaskslist();
        $owner_tasklists = array();
        $other_tasklists = array();
        $shared_tasklists = array();
        $save_color = false;
        foreach ($this->lists as $id => $list) {
            if (isset($hidden_tasklists[$list->id]))
                continue;

            // Gestion des paramètres du calendrier
            if (isset($color_tasklists[$list->id])) {
                $color = $color_tasklists[$list->id];
            }
            else {
                $color = $this->_random_color();
                $color_tasklists[$list->id] = $color;
                $save_color = true;
            }
            // Gestion des calendriers actifs
            if (isset($active_tasklists)
                    && is_array($active_tasklists)) {
                $active = isset($active_tasklists[$list->id]);
            }
            else {
                $active = true;
                $active_tasklists[$list->id] = 1;
            }
            // Gestion des alarmes dans les calendriers
            if (isset($alarm_tasklists)
                    && is_array($alarm_tasklists)) {
                $alarm = isset($alarm_tasklists[$list->id]);
            }
            else {
                $alarm = $list->owner == $this->user->uid;
                if ($alarm)
                    $alarm_tasklists[$list->id] = 1;
            }

            $tasklist = array(
                'id' => $list->id,
                'name' => $list->owner == $this->user->uid ? $list->name : "[".$list->owner."] ".$list->name,
                'listname' => $list->owner == $this->user->uid ? $list->name : "[".$list->owner."] ".$list->name,
                'editname' => $list->name,
                'color' => $color,
                'showalarms' => $alarm,
                'owner' => $list->owner,
                'editable' => $list->asRight(LibMelanie\Config\ConfigMelanie::WRITE),
                'norename' => $list->owner != $this->user->uid,
                'active' => $active,
                'parentfolder' => null,
                'default' => $default_calendar->id == $list->id,
                'children' => false,  // TODO: determine if that folder indeed has child folders
                'class_name' => trim(($list->owner == $this->user->uid ? 'personnal' : 'other') . ' ' . ($default_calendar->id == $list->id ? 'default' : '')),
            );
            // Ajout la liste de taches dans la liste correspondante
            if ($tasklist['owner'] != $this->user->uid) {
                $shared_tasklists[$id] = $tasklist;
            }
            elseif ($this->user->uid == $tasklist['id']) {
                $owner_tasklists[$id] = $tasklist;
            }
            else {
                $other_tasklists[$id] = $tasklist;
            }
        }
        // Tri des tableaux
        asort($owner_tasklists);
        asort($other_tasklists);
        asort($shared_tasklists);

        $this->rc->user->save_prefs(array(
                    'color_tasklists' => $color_tasklists,
                    'active_tasklists' => $active_tasklists,
                    'alarm_tasklists' => $alarm_tasklists,
            ));
        // Retourne la concaténation des agendas pour avoir une liste ordonnée
        return $owner_tasklists + $other_tasklists + $shared_tasklists;
    }

    /**
     * Create a new list assigned to the current user
     *
     * @param array Hash array with list properties
     *        name: List name
     *       color: The color of the list
     *  showalarms: True if alarms are enabled
     * @return mixed ID of the new list on success, False on error
     */
    public function create_list($prop)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::create_list()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::create_list() : " . var_export($prop, true));

        $tasklist = new LibMelanie\Api\Melanie2\Taskslist($this->user);
        $tasklist->name = $prop['name'];
        $tasklist->id = isset($prop['id']) ? $prop['id'] : md5($prop['name'] . time() . $this->user->uid);
        $tasklist->owner = $this->user->uid;
        $saved = $tasklist->save();
        if (!is_null($saved)) {
            // Récupération des préférences de l'utilisateur
            $color_tasklists = $this->rc->config->get('color_tasklists', array());
            $active_tasklists = $this->rc->config->get('active_tasklists', array());
            $alarm_tasklists = $this->rc->config->get('alarm_tasklists', array());
        	// Display cal
        	$active_tasklists[$tasklist->id] = 1;
        	// Color cal
        	$color_tasklists[$tasklist->id] = $prop['color'];
        	// Showalarm ?
        	if ($prop['showalarms'] == 1) {
        		$alarm_tasklists[$calendar->id] = 1;
        	}
        	$this->rc->user->save_prefs(array(
                    'color_tasklists' => $color_tasklists,
                    'active_tasklists' => $active_tasklists,
                    'alarm_tasklists' => $alarm_tasklists,
            ));

        	// Return the calendar id
        	return $tasklist->id;
        }
        else {
            return false;
        }
    }

    /**
     * Update properties of an existing tasklist
     *
     * @param array Hash array with list properties
     *          id: List Identifier
     *        name: List name
     *       color: The color of the list
     *  showalarms: True if alarms are enabled (if supported)
     * @return boolean True on success, Fales on failure
     */
    public function edit_list($prop)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::edit_list()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::edit_list() : " . var_export($prop, true));
        if (isset($prop['id'])
                && isset($this->lists[$prop['id']])) {
            $list = $this->lists[$prop['id']];
            if (isset($prop['name'])
                    && $list->owner == $this->user->uid
                    && $prop['name'] != ""
                    && $prop['name'] != $cal->name) {
                $list->name = $prop['name'];
                $list->save();
            }
            // Récupération des préférences de l'utilisateur
            $color_tasklists = $this->rc->config->get('color_tasklists', array());
            $alarm_tasklists = $this->rc->config->get('alarm_tasklists', array());
            $param_change = false;
            if (!isset($color_tasklists[$list->id])
                  		|| $color_tasklists[$list->id] != $prop['color']) {
                $color_tasklists[$list->id] = $prop['color'];
                $param_change = true;
            }
            if (!isset($alarm_tasklists[$list->id])
                  		&& $prop['showalarms'] == 1) {
                $alarm_tasklists[$list->id] = 1;
                $param_change = true;
            } elseif (isset($alarm_tasklists[$list->id])
                  		&& $prop['showalarms'] == 0) {
                unset($alarm_tasklists[$list->id]);
                $param_change = true;
            }
            if ($param_change) {
                $this->rc->user->save_prefs(array(
                    'color_tasklists' => $color_tasklists,
                    'alarm_tasklists' => $alarm_tasklists,
                ));
            }
            return true;
        }
        return false;
    }

    /**
     * Set active/subscribed state of a list
     *
     * @param array Hash array with list properties
     *          id: List Identifier
     *      active: True if list is active, false if not
     * @return boolean True on success, Fales on failure
     */
    public function subscribe_list($prop)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::subscribe_list(".$prop['id'].")");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::subscribe_list() : " . var_export($prop, true));
        // Récupération des préférences de l'utilisateur
        $active_tasklists = $this->rc->config->get('active_tasklists', array());

        if (!$prop['active'])
            unset($active_tasklists[$prop['id']]);
        else
            $active_tasklists[$prop['id']] = 1;

        return $this->rc->user->save_prefs(array('active_tasklists' => $active_tasklists));
    }

    /**
     * Delete the given list with all its contents
     *
     * @param array Hash array with list properties
     *      id: list Identifier
     * @return boolean True on success, Fales on failure
     */
    public function remove_list($prop)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::remove_list(".$prop['id'].")");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::remove_list() : " . var_export($prop, true));
        if (isset($prop['id'])
                && isset($this->lists[$prop['id']])
                && $this->lists[$prop['id']]->owner == $this->user->uid
                && $this->lists[$prop['id']]->id != $this->user->uid) {
            // Récupération des préférences de l'utilisateur
            $hidden_tasklists = $this->rc->config->get('hidden_tasklists', array());
            $color_tasklists = $this->rc->config->get('color_tasklists', array());
            $active_tasklists = $this->rc->config->get('active_tasklists', array());
            $alarm_tasklists = $this->rc->config->get('alarm_tasklists', array());
            unset($hidden_tasklists[$prop['id']]);
            unset($active_tasklists[$prop['id']]);
            unset($color_tasklists[$prop['id']]);
            unset($alarm_tasklists[$prop['id']]);
            $this->rc->user->save_prefs(array(
                'color_tasklists' => $color_tasklists,
                'active_tasklists' => $active_tasklists,
                'alarm_tasklists' => $alarm_tasklists,
                'hidden_tasklists' => $hidden_tasklists,
            ));
            return $this->lists[$prop['id']]->delete();
        }
        return false;
    }

    /**
     * Get number of tasks matching the given filter
     *
     * @param array List of lists to count tasks of
     * @return array Hash array with counts grouped by status (all|flagged|completed|today|tomorrow|nodate)
     */
    public function count_tasks($lists = null)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::count_tasks()");
        if (empty($lists))
            $lists = array_keys($this->lists);
        else if (is_string($lists))
            $lists = explode(',', $lists);

        $today_date = new DateTime('now', $this->plugin->timezone);
        $today = $today_date->format('Y-m-d');
        $tomorrow_date = new DateTime('now + 1 day', $this->plugin->timezone);
        $tomorrow = $tomorrow_date->format('Y-m-d');

        $counts = array('all' => 0, 'flagged' => 0, 'today' => 0, 'tomorrow' => 0, 'overdue' => 0, 'nodate' => 0);
        foreach ($lists as $list_id) {
            foreach($this->lists[$list_id]->getAllTasks() as $task) {
                $this->tasks[$task->id] = $task;
                $rec = $this->_to_rcube_task($task);

                if ($rec['complete'] >= 1.0)  // don't count complete tasks
                    continue;

                $counts['all']++;
                if ($rec['flagged'])
                    $counts['flagged']++;
                if (empty($rec['date']))
                    $counts['nodate']++;
                else if ($rec['date'] == $today)
                    $counts['today']++;
                else if ($rec['date'] == $tomorrow)
                    $counts['tomorrow']++;
                else if ($rec['date'] < $today)
                    $counts['overdue']++;
            }
        }
        return $counts;
    }

    /**
     * Get all taks records matching the given filter
     *
     * @param array Hash array with filter criterias:
     *  - mask:  Bitmask representing the filter selection (check against tasklist::FILTER_MASK_* constants)
     *  - from:  Date range start as string (Y-m-d)
     *  - to:    Date range end as string (Y-m-d)
     *  - search: Search query string
     * @param array List of lists to get tasks from
     * @return array List of tasks records matchin the criteria
     */
    public function list_tasks($query, $lists = null)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::list_tasks()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::list_tasks() : " . var_export($query, true));

        if (empty($lists))
            $lists = array_keys($this->lists);
        else if (is_string($lists))
            $lists = explode(',', $lists);

        $results = array();
        // Création de la requête
        $filter = "#taskslist#";
        $operators = array();
        $case_unsensitive_fields = array();
        $task = new LibMelanie\Api\Melanie2\Task($this->user);
        // Listes des tâches
        $task->taskslist = $lists;
        $operators['taskslist'] = LibMelanie\Config\MappingMelanie::eq;
        if (isset($query['mask'])) {
            // Completed ?
            if ($query['mask'] & tasklist::FILTER_MASK_COMPLETE) {
                $task->completed = 1;
                $filter .= " AND #completed#";
                $operators['completed'] = LibMelanie\Config\MappingMelanie::eq;
            }
            // Priority ?
            if ($query['mask'] & tasklist::FILTER_MASK_FLAGGED) {
                $task->priority = LibMelanie\Api\Melanie2\Task::PRIORITY_VERY_HIGH;
                $filter .= " AND #priority#";
                $operators['priority'] = LibMelanie\Config\MappingMelanie::eq;
            }
        } else {
            $task->completed = 1;
            $filter .= " AND #completed#";
            $operators['completed'] = LibMelanie\Config\MappingMelanie::inf;
        }
        // Start date
        if (isset($query['from'])) {
            $task->due = strtotime($query['from']);
            $filter .= " AND #due#";
            $operators['due'] = LibMelanie\Config\MappingMelanie::supeq;
        }
        // End date
        if (isset($query['to'])) {
            $task->start = strtotime($query['to']);
            $filter .= " AND #start#";
            $operators['start'] = LibMelanie\Config\MappingMelanie::infeq;
        }
        // Search string
        if (isset($query['search'])
                && !empty($query['search'])) {
            $task->name = '%'.$query['search'].'%';
            $task->description = '%'.$query['search'].'%';
            $filter .= " AND (#name# OR #description#)";
            $operators['name'] = LibMelanie\Config\MappingMelanie::like;
            $operators['description'] = LibMelanie\Config\MappingMelanie::like;
            $case_unsensitive_fields[] = 'name';
            $case_unsensitive_fields[] = 'description';
        }
        // Since
        if (isset($query['since'])
                && $query['since']) {
            $task->modified = $query['since'];
            $filter .= " AND #modified#";
            $operators['modified'] = LibMelanie\Config\MappingMelanie::supeq;
        }
        // Récupère la liste et génére le tableau
        foreach ($task->getList(null, $filter, $operators, 'name', true, null, null, $case_unsensitive_fields) as $object) {
            if ($this->lists[$object->taskslist]->asRight(LibMelanie\Config\ConfigMelanie::READ)) {
                // TODO: post-filter tasks returned from storage
                $results[] = $this->_to_rcube_task($object);
            }
        }

        return $results;
    }

    /**
     * Return data of a specific task
     *
     * @param mixed  Hash array with task properties or task UID
     * @return array Hash array with task properties or false if not found
     */
    public function get_task($prop)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::get_task(".$prop['id'].")");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::get_task() : " . var_export($prop, true));

        $task = new LibMelanie\Api\Melanie2\Task($this->user);
        // ID / UID
        if (isset($prop['id'])) $task->id = $prop['id'];
        elseif (isset($prop['uid'])) $task->uid = $prop['uid'];
        else return false;
        // Tasklist
        if (isset($prop['_fromlist'])) $task->taskslist = $prop['_fromlist'];
        elseif (isset($prop['list'])) $task->taskslist = $prop['list'];
        else $task->taskslist = array_keys($this->lists);
        // find task in the available folders
        foreach ($task->getList() as $object) {
            return $this->_to_rcube_task($object);
        }
        return false;
    }

    /**
     * Get all decendents of the given task record
     *
     * @param mixed  Hash array with task properties or task UID
     * @param boolean True if all childrens children should be fetched
     * @return array List of all child task IDs
     */
    public function get_childs($prop, $recursive = false)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::get_childs()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::get_childs() : " . var_export($prop, true));

        if (is_string($prop)) {
            $task = $this->get_task($prop);
            $prop = array('id' => $task['id'], 'list' => $task['list']);
        }

        $childs = array();
        $list_id = $prop['list'];
        $task_ids = array($prop['id']);

        $task = new LibMelanie\Api\Melanie2\Task($this->user);
        $task->taskslist = $list_id;
        $task->parent = $task_ids;

        // query for childs (recursively)
        foreach ($task->getList() as $object) {
            $childs[] = $object->id;
            if ($recursive) {
                $childs = array_merge($childs, $this->get_childs(array('id' => $object->id, 'list' => $list_id), true));
            }
        }

        return $childs;
    }

    /**
     * Get a list of pending alarms to be displayed to the user
     *
     * @param  integer Current time (unix timestamp)
     * @param  mixed   List of list IDs to show alarms for (either as array or comma-separated string)
     * @return array   A list of alarms, each encoded as hash array with task properties
     * @see tasklist_driver::pending_alarms()
     */
    public function pending_alarms($time, $lists = null)
    {
        return false;
    }

    /**
     * (User) feedback after showing an alarm notification
     * This should mark the alarm as 'shown' or snooze it for the given amount of time
     *
     * @param  string  Task identifier
     * @param  integer Suspend the alarm for this number of seconds
     */
    public function dismiss_alarm($id, $snooze = 0)
    {
        return false;
    }

    /**
     * Convert from Melanie2 format to internal representation
     * @param LibMelanie\Api\Melanie2\Task $record
     */
    private function _to_rcube_task(LibMelanie\Api\Melanie2\Task $record)
    {
        $task = array(
            'id' => $record->id,
            'uid' => $record->uid,
            'title' => $record->name,
            'description' => $record->description,
            'tags' => array_filter(explode(',', $record->category)),
            'flagged' => $record->priority == LibMelanie\Api\Melanie2\Task::PRIORITY_VERY_HIGH,
            'parent_id' => $record->parent,
            'list' => $record->taskslist,
            '_hasdate' => 0,
        );

        // Gestion du pourcentage de complete
        if ($record->completed == 1) {
            $task['complete'] = floatval($record->completed);
        }
        else {
            try {
                if (isset($record->percent_complete)) {
                    // La propriété existe
                    $task['complete'] = floatval($record->percent_complete / 100);
                }
                else {
                    // La propriété n'existe pas
                    $task['complete'] = floatval(0);
                }
            }
            catch (Exception $ex) {
                // Exception dans la lecture des données
                $task['complete'] = floatval(0);
            }
        }

        // convert from DateTime to internal date format
        if (isset($record->due)
                && $record->due !== 0) {
            $due = new DateTime(date(self::DB_DATE_FORMAT, $record->due), $this->plugin->timezone);
            $task['date'] = $due->format('Y-m-d');
            if (!$due->_dateonly)
                $task['time'] = $due->format('H:i');
            else
                $task['time'] = '';
            $task['_hasdate'] = 1;
        } else {
            $task['date'] = '';
            $task['time'] = '';
        }

        // convert from DateTime to internal date format
        if (isset($record->start)
                && $record->start != 0) {
            $start = new DateTime(date(self::DB_DATE_FORMAT, $record->start), $this->plugin->timezone);
            $task['startdate'] = $start->format('Y-m-d');
            if (!$start->_dateonly)
                $task['starttime'] = $start->format('H:i');
            else
                $task['starttime'] = '';
            $task['_hasdate'] = 1;
        } else {
            $task['startdate'] = '';
            $task['starttime'] = '';
        }

        if (isset($record->modified)) {
            $task['changed'] = new DateTime(date(self::DB_DATE_FORMAT, $record->modified), $this->plugin->timezone);
        } else {
            $task['changed'] = '';
        }

        if ($record->alarm != 0) {
            if ($record->alarm > 0) {
                $task['alarms'] = "-".$record->alarm."M:DISPLAY";
            }
            else {
                $task['alarms'] = "+".str_replace('-', '', strval($record->alarm))."M:DISPLAY";
            }
        } else {
            $task['alarms'] = '';
        }
        $task['attachments'] = array();
        return $task;
    }

    /**
    * Convert the given task record into a data structure that can be passed to melanie2 backend for saving
    * (opposite of self::_to_rcube_event())
    *
    * @return LibMelanie\Api\Melanie2\Task
     */
    private function _from_rcube_task($task, LibMelanie\Api\Melanie2\Task $object)
    {
        if (isset($task['tags'])) $object->category = implode(',', $task['tags']);

        if (!empty($task['date'])) {
            $due = new DateTime($task['date'].' '.$task['time'], $this->plugin->timezone);
            $object->due = strtotime($due->format(self::DB_DATE_FORMAT));
        }

        if (!empty($task['startdate'])) {
            $start = new DateTime($task['startdate'].' '.$task['starttime'], $this->plugin->timezone);
            $object->start = strtotime($start->format(self::DB_DATE_FORMAT));
        }
        if (isset($task['description'])) $object->description = $task['description'];
        if (isset($task['title'])) $object->name = $task['title'];
        if (isset($task['parent_id'])) $object->parent = $task['parent_id'];

        if (isset($task['sensitivity'])) {
            if ($task['sensitivity'] == 0)
                $object->class = LibMelanie\Api\Melanie2\Task::CLASS_PUBLIC;
            elseif ($task['sensitivity'] == 1)
                $object->class = LibMelanie\Api\Melanie2\Task::CLASS_PRIVATE;
            elseif ($task['sensitivity'] == 2)
                $object->class = LibMelanie\Api\Melanie2\Task::CLASS_CONFIDENTIAL;
        }

        if (isset($task['complete'])) {
            if ($task['complete'] == 1.0
                    && !isset($object->completed_date)) {
                $object->completed_date = time();
            }
            if ($task['complete'] == 1.0) {
                $object->completed = 1;
                $object->status = LibMelanie\Api\Melanie2\Task::STATUS_COMPLETED;
                $object->percent_complete = null;
            }
            elseif ($task['complete'] == 0) {
                $object->completed = 0;
                $object->status = null;
                $object->percent_complete = null;
            }
            else {
                $object->completed = 0;
                $object->percent_complete = $task['complete'] * 100;
                $object->status = LibMelanie\Api\Melanie2\Task::STATUS_IN_PROCESS;
            }
        }

        // TODO: Mettre à jour le plugin taskslist pour la gestion des alarmes
//         if (isset($task['alarms']) && !empty($task['alarms'])) {
//           $valarm = explode(':', $event['alarms']);
//           if (isset($valarm[0])) {
//             $object->alarm = self::valarm_ics_to_minutes_trigger($valarm[0]);
//           }
//         }

        if ($task['flagged'])
            $object->priority = LibMelanie\Api\Melanie2\Task::PRIORITY_VERY_HIGH;
        else
            $object->priority = LibMelanie\Api\Melanie2\Task::PRIORITY_NORMAL;

        return $object;
    }

    /**
     * Add a single task to the database
     *
     * @param array Hash array with task properties (see header of tasklist_driver.php)
     * @return mixed New task ID on success, False on error
     */
    public function create_task($task)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::create_task()");
        return $this->edit_task($task);
    }

    /**
     * Update an task entry with the given data
     *
     * @param array Hash array with task properties (see header of tasklist_driver.php)
     * @return boolean True on success, False on error
     */
    public function edit_task($task)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::edit_task()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::edit_task() : " . var_export($task, true));

        $list_id = $task['list'];
        if (!$list_id
                || !isset($this->lists[$list_id])
                || !$this->lists[$list_id]->asRight(LibMelanie\Config\ConfigMelanie::WRITE))
            return false;

        // moved from another folder
        if (isset($task['_fromlist'])) {
            $_task = array('id' => $task['id'], 'list' => $task['_fromlist']);
            $this->delete_task($_task);
            unset($task['id']);
        }

        // load previous version of this task to merge
        if ($task['id']) {
            $object = new LibMelanie\Api\Melanie2\Task($this->user, $this->lists[$list_id]);
            $object->id = $task['id'];
            foreach ($object->getList() as $t) {
                $_task = $t;
                break;
            }
            // La tache n'a pas pu être récupéré
            if (!isset($_task))
                return false;
        } else {
            $_task = new LibMelanie\Api\Melanie2\Task($this->user, $this->lists[$list_id]);
            if (isset($task['uid'])) {
                $_task->uid = $task['uid'];
            } else {
                $_task->uid = date('Ymd').time().md5($list_id.strval(time())).'@roundcube';
            }
            $_task->id = md5(time().$_task->uid.uniqid());
            $_task->owner = $this->user->uid;
        }
        $_task->modified = time();

        // generate new task object from RC input
        $_task = $this->_from_rcube_task($task, $_task);
        $saved = $_task->save();

        return !is_null($saved);
    }

    /**
     * Move a single task to another list
     *
     * @param array   Hash array with task properties:
     * @return boolean True on success, False on error
     * @see tasklist_driver::move_task()
     */
    public function move_task($task)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::move_task()");
        return $this->edit_task($task);
    }

    /**
     * Remove a single task from the database
     *
     * @param array   Hash array with task properties:
     *      id: Task identifier
     * @param boolean Remove record irreversible (mark as deleted otherwise, if supported by the backend)
     * @return boolean True on success, False on error
     */
    public function delete_task($task, $force = true)
    {
        if ($this->rc->task != 'tasks') {
          return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[tasklist] tasklist_melanie2_driver::delete_task()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::delete_task() : " . var_export($task, true));

        $list_id = $task['list'];
        if (!$list_id
                || !isset($this->lists[$list_id])
                || !isset($task['id'])
                || !$this->lists[$list_id]->asRight(LibMelanie\Config\ConfigMelanie::WRITE))
            return false;

        $object = new LibMelanie\Api\Melanie2\Task($this->user, $this->lists[$list_id]);
        $object->id = $task['id'];
        foreach ($object->getList() as $t) {
            return $t->delete();
        }
    }

    /**
     * Restores a single deleted task (if supported)
     *
     * @param array Hash array with task properties:
     *      id: Task identifier
     * @return boolean True on success, False on error
     */
    public function undelete_task($prop)
    {
        // TODO: implement this
        return false;
    }


    /**
     * Get attachment properties
     *
     * @param string $id    Attachment identifier
     * @param array  $task  Hash array with event properties:
     *         id: Task identifier
     *       list: List identifier
     *
     * @return array Hash array with attachment properties:
     *         id: Attachment identifier
     *       name: Attachment name
     *   mimetype: MIME content type of the attachment
     *       size: Attachment size
     */
    public function get_attachment($id, $task)
    {
        return null;
    }

    /**
     * Get attachment body
     *
     * @param string $id    Attachment identifier
     * @param array  $task  Hash array with event properties:
     *         id: Task identifier
     *       list: List identifier
     *
     * @return string Attachment body
     */
    public function get_attachment_body($id, $task)
    {
        return false;
    }

    /**
     *
     */
    public function tasklist_edit_form($fieldprop)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[tasklist] tasklist_melanie2_driver::tasklist_edit_form() : " . var_export($fieldprop, true));
        /*
        $select = kolab_storage::folder_selector('task', array('name' => 'parent', 'id' => 'taskedit-parentfolder'), null);
        $fieldprop['parent'] = array(
            'id' => 'taskedit-parentfolder',
            'label' => $this->plugin->gettext('parentfolder'),
            'value' => $select->show(''),
        );

        $formfields = array();
        foreach (array('name','parent','showalarms') as $f) {
            $formfields[$f] = $fieldprop[$f];
        }*/
        // Supprimer les alarmes
        unset($fieldprop['showalarms']);
        return parent::tasklist_edit_form($fieldprop);
    }

    /**
     * Calcul le trigger VALARM ICS et le converti en minutes
     *
     * @param string $trigger
     * @return number
     */
    private static function valarm_ics_to_minutes_trigger($trigger) {
      // TRIGGER au format -PT#W#D#H#M
      // Recherche les positions des caracteres
      $posT = strpos($trigger, 'T');
      $posW = strpos($trigger, 'W');
      $posD = strpos($trigger, 'D');
      $posH = strpos($trigger, 'H');
      $posM = strpos($trigger, 'M');

      // Si on trouve la position on recupere la valeur et on decale la position de reference
      if ($posT === false) {
        $posT = strpos($trigger, 'P');
      }

      $nbDay = 0;
      $nbHour = 0;
      $nbMin = 0;
      $nbWeeks = 0;
      if ($posW !== false) {
        $nbWeeks = intval(substr($trigger, $posT + 1, $posW - $posT + 1));
        $posT = $posW;
      }
      if ($posD !== false) {
        $nbDay = intval(substr($trigger, $posT + 1, $posD - $posT + 1));
        $posT = $posD;
      }
      if ($posH !== false) {
        $nbHour = intval(substr($trigger, $posT + 1, $posH - $posT + 1));
        $posT = $posH;
      }
      if ($posM !== false) {
        $nbMin = intval(substr($trigger, $posT + 1, $posM - $posT + 1));
      }

      // Calcul de l'alarme
      $minutes = $nbMin + $nbHour * 60 + $nbDay * 24 * 60 + $nbWeeks * 24 * 60 * 7;
      if (strpos($trigger, '-') === false)
        $minutes = - $minutes;
      return $minutes;
    }

}
