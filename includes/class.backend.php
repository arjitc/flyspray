<?php
/**
 * Flyspray
 *
 * Backend class
 *
 * This script contains reusable functions we use to modify
 * various things in the Flyspray database tables.  
 *
 * @license http://opensource.org/licenses/lgpl-license.php Lesser GNU Public License
 * @package flyspray
 * @author Tony Collins, Florian Schmitz
 */

if (!defined('IN_FS')) {
    die('Do not access this file directly.');
}

class Backend
{
    /**
     * Adds the user $user_id to the notifications list of $tasks
     * @param integer $user_id
     * @param array $tasks
     * @param bool $do Force execution independent of user permissions
     * @access public
     * @return bool
     * @version 1.0
     */
    function add_notification($user_id, $tasks, $do = false)
    {
        global $db, $user;

        settype($tasks, 'array');
        
        $user_id = Flyspray::username_to_id($user_id);

        if (!$user_id || !count($tasks)) {
            return false;
        }
        
        $sql = $db->Query(' SELECT *
                              FROM {tasks}
                             WHERE ' . substr(str_repeat(' task_id = ? OR ', count($tasks)), 0, -3),
                          $tasks);

        while ($row = $db->FetchRow($sql)) {
            // -> user adds himself
            if ($user->id == $user_id) {
                if (!$user->can_view_task($row) && !$do) {
                    continue;
                }
            // -> user is added by someone else
            } else  {
                if (!$user->perms('manage_project', $row['project_id']) && !$do) {
                    continue;
                }
            }
            
            $notif = $db->Query('SELECT notify_id
                                   FROM {notifications}
                                  WHERE task_id = ? and user_id = ?',
                              array($row['task_id'], $user_id));
           
            if (!$db->CountRows($notif)) {
                $db->Query('INSERT INTO {notifications} (task_id, user_id)
                                 VALUES  (?,?)', array($row['task_id'], $user_id));
                Flyspray::logEvent($row['task_id'], 9, $user_id);
            }
        }

        return (bool) $db->CountRows($sql);
    }


    /**
     * Removes a user $user_id from the notifications list of $tasks
     * @param integer $user_id
     * @param array $tasks
     * @access public
     * @return void
     * @version 1.0
     */

    function remove_notification($user_id, $tasks)
    {
        global $db, $user;

        settype($tasks, 'array');
        
        if (!count($tasks)) {
            return;
        }
                
        $sql = $db->Query(' SELECT *
                              FROM {tasks}
                             WHERE ' . substr(str_repeat(' task_id = ? OR ', count($tasks)), 0, -3),
                          $tasks);
                             
        while ($row = $db->FetchRow($sql)) {
            // -> user removes himself
            if ($user->id == $user_id) {
                if (!$user->can_view_task($row)) {
                    continue;
                }
            // -> user is removed by someone else
            } else  {
                if (!$user->perms('manage_project', $row['project_id'])) {
                    continue;
                }
            }
            
            $db->Query('DELETE FROM  {notifications}
                              WHERE  task_id = ? AND user_id = ?',
                        array($row['task_id'], $user_id));
            if ($db->affectedRows()) {
                Flyspray::logEvent($row['task_id'], 10, $user_id);
            }
        }
    }


    /**
     * Assigns one or more $tasks only to a user $user_id
     * @param integer $user_id
     * @param array $tasks
     * @access public
     * @return void
     * @version 1.0
     */
    function assign_to_me($user_id, $tasks)
    {
        global $db, $notify, $user;
        
        if ($user_id != $user->id) {
            $user = new User($user_id);
        }

        settype($tasks, 'array');
        if (!count($tasks)) {
            return;
        }

        $sql = $db->Query(' SELECT *
                              FROM {tasks}
                             WHERE ' . substr(str_repeat(' task_id = ? OR ', count($tasks)), 0, -3),
                          $tasks);

        while ($row = $db->FetchRow($sql)) {
            if (!$user->can_take_ownership($row)) {
                continue;
            }
            
            $db->Query('DELETE FROM {assigned}
                              WHERE task_id = ?',
                        array($row['task_id']));

            $db->Query('INSERT INTO {assigned}
                                    (task_id, user_id)
                             VALUES (?,?)',
                        array($row['task_id'], $user->id));
            
            if ($db->affectedRows()) {
                Flyspray::logEvent($row['task_id'], 19, $user->id, implode(' ', Flyspray::GetAssignees($row['task_id'])));
                $notify->Create(NOTIFY_OWNERSHIP, $row['task_id']);
            }

            if ($row['item_status'] == STATUS_UNCONFIRMED || $row['item_status'] == STATUS_NEW) {
                $db->Query('UPDATE {tasks} SET item_status = 3 WHERE task_id = ?', array($row['task_id']));
                Flyspray::logEvent($task_id, 3, 3, 1, 'item_status');
            }
        }
    }
    
    /**
     * Adds a user $user_id to the assignees of one or more $tasks
     * @param integer $user_id
     * @param array $tasks
     * @param bool $do Force execution independent of user permissions
     * @access public
     * @return void
     * @version 1.0
     */
    function add_to_assignees($user_id, $tasks, $do = false)
    {
        global $db, $notify, $user;

        if ($user_id != $user->id) {
            $user = new User($user_id);
        }

        settype($tasks, 'array');
        if (!count($tasks)) {
            return;
        }
        
        $sql = $db->Query(' SELECT *
                              FROM {tasks}
                             WHERE ' . substr(str_repeat(' task_id = ? OR ', count($tasks)), 0, -3),
                          array($tasks));

        while ($row = $db->FetchRow($sql)) {
            if (!$user->can_add_to_assignees($row) || $do) {
                continue;
            }
            
            $db->Replace('{assigned}', array('user_id'=> $user->id, 'task_id'=> $row['task_id']), array('user_id','task_id'));

            if ($db->affectedRows()) {
                Flyspray::logEvent($row['task_id'], 29, $user->id, implode(' ', Flyspray::GetAssignees($row['task_id'])));
                $notify->Create(NOTIFY_ADDED_ASSIGNEES, $row['task_id']);
            }
            
            if ($task['item_status'] == STATUS_UNCONFIRMED || $task['item_status'] == STATUS_NEW) {
                $db->Query('UPDATE {tasks} SET item_status = 3 WHERE task_id = ?', array($row['task_id']));
                Flyspray::logEvent($row['task_id'], 3, 3, 1, 'item_status');
            }
        }
    }
    
    /**
     * Adds a vote from $user_id to the task $task_id
     * @param integer $user_id
     * @param integer $task_id
     * @access public
     * @return bool
     * @version 1.0
     */
    function add_vote($user_id, $task_id)
    {
        global $db, $user;
        
        if ($user_id != $user->id) {
            $user = new User($user_id);
        }
        
        $task = Flyspray::GetTaskDetails($task_id);
        
        if ($user->can_vote($task) > 0) { 
            
            if($db->Query("INSERT INTO {votes} (user_id, task_id, date_time)
                           VALUES (?,?,?)", array($user->id, $task_id, time()))) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Adds a comment to $task
     * @param array $task
     * @param string $comment_text
     * @param integer $time for synchronisation with other functions
     * @access public
     * @return bool
     * @version 1.0
     */
    function add_comment($task, $comment_text, $time = null)
    {
        global $db, $user, $notify;
        
        if (!($user->perms('add_comments', $task['project_id']) && (!$task['is_closed'] || $user->perms('comment_closed', $task['project_id'])))) {
            return false;
        }
        
        if (!is_string($comment_text) || !strlen($comment_text)) {
            return false;
        }
        
        $time =  is_null($time) ? time() : $time ;

        $db->Query('INSERT INTO  {comments}
                                 (task_id, date_added, last_edited_time, user_id, comment_text)
                         VALUES  ( ?, ?, ?, ?, ? )',
                    array($task['task_id'], $time, $time, $user->id, $comment_text));

        $result = $db->Query('SELECT  comment_id
                                FROM  {comments}
                               WHERE  task_id = ?
                            ORDER BY  comment_id DESC',
                            array($task['task_id']), 1);
        $cid = $db->FetchOne($result);

        Flyspray::logEvent($task['task_id'], 4, $cid);

        if (Backend::upload_files($task['task_id'], $cid)) {
            $notify->Create(NOTIFY_COMMENT_ADDED, $task['task_id'], 'files');
        } else {
            $notify->Create(NOTIFY_COMMENT_ADDED, $task['task_id']);
        }

        return true;
    }

    /**
     * Upload files for a comment or a task
     * @param integer $task_id
     * @param integer $comment_id if it is 0, the files will be attached to the task itself
     * @param string $source name of the file input
     * @access public
     * @return bool
     * @version 1.0
     */
    function upload_files($task_id, $comment_id = 0, $source = 'userfile')
    {
        global $db, $notify, $conf, $user;

        mt_srand(Flyspray::make_seed());

        $task = Flyspray::GetTaskDetails($task_id);

        if (!$user->perms('create_attachments', $task['project_id'])) {
            return false;
        }

        $res = false;
		
		if (!isset($_FILES[$source]['error'])) {
			return false;
		}

        foreach ($_FILES[$source]['error'] as $key => $error) {
            if ($error != UPLOAD_ERR_OK) {
                continue;
            }

            $fname = $task_id.'_'.mt_rand();
            while (is_file($path = BASEDIR .'/attachments/'.$fname)) {
                $fname = $task_id.'_'.mt_rand();
            }

            $tmp_name = $_FILES[$source]['tmp_name'][$key];

            // Then move the uploaded file and remove exe permissions
            if(!@move_uploaded_file($tmp_name, $path)) {
                //upload failed. continue    
                continue;
            }

            @chmod($path, 0644);
            $res = true;
            
            // Use a different MIME type
            $extension = end(explode('.', $_FILES[$source]['name'][$key]));
            if (isset($conf['attachments'][$extension])) {
                $_FILES[$source]['type'][$key] = $conf['attachments'][$extension];
            }

            $db->Query("INSERT INTO  {attachments}
                                     ( task_id, comment_id, file_name,
                                       file_type, file_size, orig_name,
                                       added_by, date_added )
                             VALUES  (?, ?, ?, ?, ?, ?, ?, ?)",
                    array($task_id, $comment_id, $fname,
                        $_FILES[$source]['type'][$key],
                        $_FILES[$source]['size'][$key],
                        $_FILES[$source]['name'][$key],
                        $user->id, time()));

            // Fetch the attachment id for the history log
            $result = $db->Query('SELECT  attachment_id
                                    FROM  {attachments}
                                   WHERE  task_id = ?
                                ORDER BY  attachment_id DESC',
                    array($task_id), 1);
            Flyspray::logEvent($task_id, 7, $db->fetchOne($result));
        }

        return $res;
    }

    /**
     * Delete one or more attachments of a task or comment
     * @param array $attachments
     * @access public
     * @return void
     * @version 1.0
     */
    function delete_files($attachments)
    {
        global $db, $user;

        settype($attachments, 'array');
        if (!count($attachments)) {
            return;
        }

        $sql = $db->Query(' SELECT t.*, a.*
                              FROM {attachments} a
                         LEFT JOIN {tasks} t ON t.task_id = a.task_id
                             WHERE ' . substr(str_repeat(' attachment_id = ? OR ', count($attachments)), 0, -3),
                          $attachments);
                             
        while ($task = $db->FetchRow($sql)) {
            if (!$user->perms('delete_attachments', $task['project_id'])) {
                continue;
            }
            
            $db->Query('DELETE FROM {attachments} WHERE attachment_id = ?',
                       array($task['attachment_id']));
            @unlink(BASEDIR . '/attachments/' . $task['file_name']);
            Flyspray::logEvent($task['task_id'], 8, $task['orig_name']);
        }
    }
    
    /**
     * Creates a new user
     * @param string $user_name
     * @param string $password
     * @param string $real_name
     * @param string $jabber_id
     * @param string $email
     * @param integer $notify_type
     * @param integer $time_zone
     * @param integer $group_in
     * @access public
     * @return bool false if username is already taken
     * @version 1.0
     * @notes This function does not have any permission checks (checked elsewhere)
     */
    function create_user($user_name, $password, $real_name, $jabber_id, $email, $notify_type, $time_zone, $group_in)
    {
        global $fs, $db, $notify, $baseurl;
        
        // Limit lengths
        $user_name = substr(trim($user_name), 0, 32);
        $real_name = substr(trim($real_name), 0, 100);
        // Remove doubled up spaces and control chars
        if (version_compare(PHP_VERSION, '4.3.4') == 1) {
            $user_name = preg_replace('![\x00-\x1f\s]+!u', ' ', $user_name);
            $real_name = preg_replace('![\x00-\x1f\s]+!u', ' ', $real_name);
        }
        // Strip special chars
        $user_name = utf8_keepalphanum($user_name);

        // Check to see if the username is available
        $sql = $db->Query('SELECT COUNT(*) FROM {users} WHERE user_name = ?', array($user_name));
        
        if ($db->fetchOne($sql)) {
            return false;
        }
        
        $auto = false;
        // Autogenerate a password
        if (!$password) {
            $auto = true;
            mt_srand(Flyspray::make_seed());
            $password = substr(md5(mt_rand() . $user_name), 0, mt_rand(7, 9));
        }
        
        $db->Query("INSERT INTO  {users}
                             ( user_name, user_pass, real_name, jabber_id,
                               email_address, notify_type, account_enabled,
                               tasks_perpage, register_date, time_zone)
                     VALUES  ( ?, ?, ?, ?, ?, ?, 1, 25, ?, ?)",
            array($user_name, Flyspray::cryptPassword($password), $real_name, $jabber_id, $email, $notify_type, time(), $time_zone));

        // Get this user's id for the record
        $uid = Flyspray::username_to_id($user_name);

        // Now, create a new record in the users_in_groups table
        $db->Query('INSERT INTO  {users_in_groups} (user_id, group_id)
                         VALUES  ( ?, ?)', array($uid, $group_in));
        
        Flyspray::logEvent(0, 30, serialize(Flyspray::getUserDetails($uid)));
        
        // Send a user his details (his username might be altered, password auto-generated)
        if ($fs->prefs['notify_registration']) {
            $sql = $db->Query('SELECT email_address
                                 FROM {users} u
                            LEFT JOIN {users_in_groups} g ON u.user_id = g.user_id
                                WHERE g.group_id = 1');
            $notify->Create(NOTIFY_NEW_USER, null,
                            array($baseurl, $user_name, $real_name, $email, $jabber_id, $password, $auto),
                            $db->FetchAllArray($sql), NOTIFY_EMAIL);
        }
        
        return true;
    }

    /**
     * Deletes a user
     * @param integer $uid
     * @access public
     * @return bool
     * @version 1.0
     */
    function delete_user($uid)
    {
        global $db, $user;
        
        if (!$user->perms('is_admin')) {
            return false;
        }
        
        $user_data = serialize(Flyspray::getUserDetails($uid));
        $tables = array('users', 'users_in_groups', 'searches',
                        'notifications', 'assigned');
        
        foreach ($tables as $table) {
            if (!$db->Query('DELETE FROM ' .'{' . $table .'}' . ' WHERE user_id = ?', array($uid))) {
                return false;
            }
        }
        
        Flyspray::logEvent(0, 31, $user_data);
        
        return (bool) $db->affectedRows($sql);
    }

    /**
     * Deletes a project
     * @param integer $pid
     * @param integer $move_to to which project contents of the project are moved
     * @access public
     * @return bool
     * @version 1.0
     */
    function delete_project($pid, $move_to = 0)
    {
        global $db, $user;
        
        if (!$user->perms('manage_project', $pid)) {
            return false;
        }
        
        $tables = array('list_category', 'list_os', 'list_resolution', 'list_tasktype',
                        'list_status', 'list_version', 'admin_requests', 
                        'cache', 'projects', 'tasks');
        
        foreach ($tables as $table) {
            if ($move_to && $table !== 'projects') {
                $action = 'UPDATE ';
                $sql_params = array($move_to, $pid);
            } else {
                $action = 'DELETE FROM ';
                $sql_params = array($pid);
                //tp will be true or false ;)
                $tp = ($table === 'projects');
            }
            
            $base_sql = $action . '{'. $table .'}'. (($move_to && empty($tp))  ? ' SET project_id = ? ': '');
 
            if (!$db->Query($base_sql . ' WHERE project_id = ?', $sql_params)) {
                return false;
            }
             //we need this for the next loop.
            unset($tp); 
        }
        
        // groups are only deleted, not moved (it is likely
        // that the destination project already has all kinds
        // of groups which are also used by the old project)
        $sql = $db->Query('SELECT group_id FROM {groups} WHERE project_id = ?', array($pid));
        while ($row = $db->FetchRow($sql)) {
            $db->Query('DELETE FROM {users_in_groups} WHERE group_id = ?', array($row['project_id']));
        }
        $sql = $db->Query('DELETE FROM {groups} WHERE project_id = ?', array($pid));        
        
        //we have enough reasons ..  the process is OK. 
        return true;        
    }

    /**
     * Adds a reminder to a task
     * @param integer $task_id
     * @param string $message
     * @param integer $how_often send a reminder every ~ seconds
     * @param integer $start_time time when the reminder starts
     * @param $user_id the user who is reminded. by default (null) all users assigned to the task are reminded.
     * @access public
     * @return bool
     * @version 1.0
     */
    function add_reminder($task_id, $message, $how_often, $start_time, $user_id = null)
    {
        global $user, $db;
        $task = Flyspray::GetTaskDetails($task_id);
        
        if (!$user->perms('manage_project', $task['project_id'])) {
            return false;
        }
        
        if (is_null($user_id)) {
            // Get all users assigned to a task
            $user_id = Flyspray::GetAssignees($task_id);
        } else {
            $user_id = array(Flyspray::username_to_id($user_id));
            if (!reset($user_id)) {
                return false;
            }
        }

        foreach ($user_id as $id) {
            $sql = $db->Replace('{reminders}',
                                array('task_id'=> $task_id, 'to_user_id'=> $id,
                                     'from_user_id' => $user->id, 'start_time' => $start_time,
                                     'how_often' => $how_often, 'reminder_message' => $message),
                                array('task_id', 'to_user_id', 'how_often', 'reminder_message'));
            if(!$sql) {
                // query has failed :( 
                return false;
            }
        }
        // 2 = no record has found and was INSERT'ed correclty
        if (isset($sql) && $sql == 2) {
            Flyspray::logEvent($task_id, 17, $task_id);
        }
        return true;
    }

    /**
     * Adds a new task
     * @param array $args array containing all task properties. unknown properties will be ignored
     * @access public
     * @return integer the task ID on success
     * @version 1.0
     */
    function create_task($args)
    {
        global $db, $user, $proj;
        $notify = new Notifications();
        if ($proj->id !=  $args['project_id']) {
            $proj = new Project($args['project_id']);
        }
        
        if (!$user->can_open_task($proj) || count($args) < 3) {
            return 0;
        }

        if (!(($item_summary = $args['item_summary']) && ($detailed_desc = $args['detailed_desc']))) {
            return 0;
        }
        
        // Some fields can have default values set
        if (!$user->perms('modify_all_tasks')) {
            $args['closedby_version'] = 0;
            $args['task_priority'] = 2;
            $args['due_date'] = 0;
            $args['item_status'] = STATUS_UNCONFIRMED;
        } 

        $param_names = array('task_type', 'item_status',
                'product_category', 'product_version', 'closedby_version',
                'operating_system', 'task_severity', 'task_priority');

        $sql_values = array(time(), time(), $args['project_id'], $item_summary,
                $detailed_desc, intval($user->id), '0');

        $sql_params = array();
        foreach ($param_names as $param_name) {
            if (isset($args[$param_name])) {
                $sql_params[] = $param_name;
                $sql_values[] = $args[$param_name];
            }
        }

        // Process the due_date
        if ( ($due_date = $args['due_date']) || ($due_date = 0) ) {
            $due_date = strtotime("$due_date +23 hours 59 minutes 59 seconds");
        }

        $sql_params[] = 'due_date';
        $sql_values[] = $due_date;
        
        $sql_params[] = 'closure_comment';
        $sql_values[] = '';
        
        // Token for anonymous users
        if ($user->isAnon()) {
            $token = md5(time() . $_SERVER['REQUEST_URI'] . mt_rand() . microtime());
            $sql_params[] = 'task_token';
            $sql_values[] = $token;
            
            $sql_params[] = 'anon_email';
            $sql_values[] = $args['anon_email'];
        }
        
        $sql_params = join(', ', $sql_params);
        // +1 for the task_id column;
        $sql_placeholder = $db->fill_placeholders($sql_values, 1);
        
        $result = $db->Query('SELECT  max(task_id)+1
                                FROM  {tasks}');
        $task_id = $db->FetchOne($result); 
        $task_id = $task_id ? $task_id : 1;
        //now, $task_id is always the first element of $sql_values 
        array_unshift($sql_values, $task_id);
                            
        $result = $db->Query("INSERT INTO  {tasks}
                                 ( task_id, date_opened, last_edited_time,
                                   project_id, item_summary,
                                   detailed_desc, opened_by,
                                   percent_complete, $sql_params )
                         VALUES  ($sql_placeholder)", $sql_values);

        // Log the assignments and send notifications to the assignees
        if (trim($args['assigned_to']))
        {
            // Convert assigned_to and store them in the 'assigned' table
            foreach (Flyspray::int_explode(' ', trim($args['assigned_to'])) as $key => $val)
            {
                $db->Replace('{assigned}', array('user_id'=> $val, 'task_id'=> $task_id), array('user_id','task_id'));
            }
            // Log to task history
            Flyspray::logEvent($task_id, 14, trim($args['assigned_to']));
        }


        // Notify the new assignees what happened.  This obviously won't happen if the task is now assigned to no-one.
        $notify->Create(NOTIFY_NEW_ASSIGNEE, $task_id, null,
                        $notify->SpecificAddresses(Flyspray::int_explode(' ', $args['assigned_to'])));
                    
        // Log that the task was opened
        Flyspray::logEvent($task_id, 1);

        Backend::upload_files($task_id);

        $result = $db->Query('SELECT  *
                                FROM  {list_category}
                               WHERE  category_id = ?',
                               array($args['product_category']));
        $cat_details = $db->FetchRow($result);

        // We need to figure out who is the category owner for this task
        if (!empty($cat_details['category_owner'])) {
            $owner = $cat_details['category_owner'];
        }
        else {
            // check parent categories
            $result = $db->Query('SELECT  *
                                    FROM  {list_category}
                                   WHERE  lft < ? AND rgt > ? AND project_id  = ?
                                ORDER BY  lft DESC',
                                   array($cat_details['lft'], $cat_details['rgt'], $cat_details['project_id']));
            while ($row = $db->FetchRow($result)) {
                // If there's a parent category owner, send to them
                if (!empty($row['category_owner'])) {
                    $owner = $row['category_owner'];
                    break;
                }
            }
        }

        if (!isset($owner)) {
            $owner = $proj->prefs['default_cat_owner'];
        }

        if ($owner) {
            if ($proj->prefs['auto_assign'] && ($args['item_status'] == STATUS_UNCONFIRMED || $args['item_status'] == STATUS_NEW)) {
                Backend::add_to_assignees($owner, $task_id, true);
            }
            Backend::add_notification($owner, $task_id, true);
        }

        // Reminder for due_date field
        if ($due_date) {
            Backend::add_reminder($task_id, L('defaultreminder') . "\n\n" . CreateURL('details', $task_id), 2*24*60*60, time());
        }
        
        // Create the Notification
        $notify->Create(NOTIFY_TASK_OPENED, $task_id);

        // If the reporter wanted to be added to the notification list
        if ($args['notifyme'] == '1' && $user->id != $owner) {
            Backend::add_notification($user->id, $task_id, true);
        }
        
        if ($user->isAnon()) {
            $notify->Create(NOTIFY_ANON_TASK, $task_id, null, $args['anon_email']);
        }

        return $task_id;
    }

    /**
     * Closes a task
     * @param integer $task_id
     * @param integer $reason
     * @param string $comment
     * @param bool $mark100
     * @access public
     * @return bool
     * @version 1.0
     */
    function close_task($task_id, $reason, $comment, $mark100 = true)
    {
        global $db, $notify, $user;
        $task = Flyspray::GetTaskDetails($task_id);
        
        if (!$user->can_close_task($task)) {
            return false;
        }

        $db->Query('UPDATE  {tasks}
                       SET  date_closed = ?, closed_by = ?, closure_comment = ?,
                            is_closed = 1, resolution_reason = ?, last_edited_time = ?,
                            last_edited_by = ?
                     WHERE  task_id = ?',
                    array(time(), $user->id, $comment, $reason, time(), $user->id, $task_id));

        if ($mark100) {
            $db->Query('UPDATE {tasks} SET percent_complete = 100 WHERE task_id = ?',
                       array($task_id));

            Flyspray::logEvent($task_id, 3, 100, $task['percent_complete'], 'percent_complete');
        }

        $notify->Create(NOTIFY_TASK_CLOSED, $task_id);
        Flyspray::logEvent($task_id, 2, $reason, $comment);

        // If there's an admin request related to this, close it
        $db->Query('UPDATE  {admin_requests}
                       SET  resolved_by = ?, time_resolved = ?
                     WHERE  task_id = ? AND request_type = ?',
                    array($user->id, time(), $task_id, 1));
        
        // duplicate
        if ($reason == 6) {
            preg_match("/\b(?:FS#|bug )(\d+)\b/", $comment, $dupe_of);
            if (count($dupe_of) >= 2) {
                $existing = $db->Query('SELECT * FROM {related} WHERE this_task = ? AND related_task = ? AND is_duplicate = 1',
                                            array($task_id, $dupe_of[1]));
                                       
                if ($existing && $db->CountRows($existing) == 0) {  
                    $db->Query('INSERT INTO {related} (this_task, related_task, is_duplicate) VALUES(?, ?, 1)',
                                array($task_id, $dupe_of[1]));
                }
            }
            Backend::add_vote($task['opened_by'], $dupe_of[1]);
        }
        
        return true;
    }

    /**
     * Returns an array of tasks (respecting pagination) and an ID list (all tasks)
     * @param array $args
     * @param array $visible
     * @param integer $offset
     * @param integer $comment
     * @param bool $perpage
     * @access public
     * @return array
     * @version 1.0
     */
    function get_task_list($args, $visible, $offset = 0, $perpage = 20)
    {
        global $proj, $db, $user, $conf;
        /* build SQL statement {{{ */
        // Original SQL courtesy of Lance Conry http://www.rhinosw.com/
        $where  = $sql_params = array();
        $select = '';
        $groupby = '';
        $from   = '             {tasks}         t
                     LEFT JOIN  {projects}      p   ON t.project_id = p.project_id
                     LEFT JOIN  {list_tasktype} lt  ON t.task_type = lt.tasktype_id
                     LEFT JOIN  {list_status}   lst ON t.item_status = lst.status_id
                     LEFT JOIN  {list_resolution} lr ON t.resolution_reason = lr.resolution_id ';
        // Only join tables which are really necessary to speed up the db-query
        if (array_get($args, 'cat') || in_array('category', $visible)) {
            $from   .= ' LEFT JOIN  {list_category} lc  ON t.product_category = lc.category_id ';
            $select .= ' lc.category_name               AS category_name, ';
            $groupby .= 'lc.category_name, ';
        }
        if (in_array('votes', $visible)) {
            $from   .= ' LEFT JOIN  {votes} vot         ON t.task_id = vot.task_id ';
            $select .= ' COUNT(DISTINCT vot.vote_id)    AS num_votes, ';
        }
        if (array_get($args, 'changedfrom') || array_get($args, 'changedto') || in_array('lastedit', $visible)) {
            $from   .= ' LEFT JOIN  {history} h         ON t.task_id = h.task_id ';
            $select .= ' max(h.event_date)              AS event_date, ';
        }
        if (array_get($args, 'search_in_comments') || in_array('comments', $visible)) {
            $from   .= ' LEFT JOIN  {comments} c        ON t.task_id = c.task_id ';
            $select .= ' COUNT(DISTINCT c.comment_id)   AS num_comments, ';
        }
        if (in_array('reportedin', $visible)) {
            $from   .= ' LEFT JOIN  {list_version} lv   ON t.product_version = lv.version_id ';
            $select .= ' lv.version_name                AS product_version, ';
            $groupby .= 'lv.version_name, ';
        }
        if (array_get($args, 'opened') || in_array('openedby', $visible)) {
            $from   .= ' LEFT JOIN  {users} uo          ON t.opened_by = uo.user_id ';
            $select .= ' uo.real_name                   AS opened_by_name, ';
            $groupby .= 'uo.real_name, ';
        }
        if (array_get($args, 'closed')) {
            $from   .= ' LEFT JOIN  {users} uc          ON t.closed_by = uc.user_id ';
            $select .= ' uc.real_name                   AS closed_by_name, ';
            $groupby .= 'uc.real_name, ';
        }
        if (array_get($args, 'due') || in_array('dueversion', $visible)) {
            $from   .= ' LEFT JOIN  {list_version} lvc  ON t.closedby_version = lvc.version_id ';
            $select .= ' lvc.version_name               AS closedby_version, ';
            $groupby .= 'lvc.version_name, ';
        }
        if (in_array('os', $visible)) {
            $from   .= ' LEFT JOIN  {list_os} los       ON t.operating_system = los.os_id ';
            $select .= ' los.os_name                    AS os_name, ';
            $groupby .= 'los.os_name, ';
        }
        if (in_array('attachments', $visible) || array_get($args, 'has_attachment')) {
            $from   .= ' LEFT JOIN  {attachments} att   ON t.task_id = att.task_id ';
            $select .= ' COUNT(DISTINCT att.attachment_id) AS num_attachments, ';
        }

        $from   .= ' LEFT JOIN  {assigned} ass      ON t.task_id = ass.task_id ';
        $from   .= ' LEFT JOIN  {users} u           ON ass.user_id = u.user_id ';
        if (array_get($args, 'dev') || in_array('assignedto', $visible)) {
            $select .= ' min(u.real_name)               AS assigned_to_name, ';
            $select .= ' COUNT(DISTINCT ass.user_id)    AS num_assigned, ';
        }

        if (array_get($args, 'only_primary')) {
            $from   .= ' LEFT JOIN  {dependencies} dep  ON dep.dep_task_id = t.task_id ';
            $where[] = 'dep.depend_id IS NULL';
        }
        if (array_get($args, 'has_attachment')) {
            $where[] = 'att.attachment_id IS NOT NULL';
        }

        if ($proj->id) {
            $where[]       = 't.project_id = ?';
            $sql_params[]  = $proj->id;
        }

        $order_keys = array (
                'id'           => 't.task_id',
                'project'      => 'project_title',
                'tasktype'     => 'tasktype_name',
                'dateopened'   => 'date_opened',
                'summary'      => 'item_summary',
                'severity'     => 'task_severity',
                'category'     => 'lc.category_name',
                'status'       => 'item_status',
                'dueversion'   => 'lvc.list_position',
                'duedate'      => 'due_date',
                'progress'     => 'percent_complete',
                'lastedit'     => 'event_date',
                'priority'     => 'task_priority',
                'openedby'     => 'uo.real_name',
                'reportedin'   => 't.product_version',
                'assignedto'   => 'u.real_name',
                'dateclosed'   => 't.date_closed',
                'os'           => 'los.os_name',
                'votes'        => 'num_votes',
                'attachments'  => 'num_attachments',
                'comments'     => 'num_comments',
        );
        
        // make sure that only columns can be sorted that are visible
        $order_keys = array_intersect_key($order_keys, array_flip($visible));

        $order_column[0] = $order_keys[Filters::enum(array_get($args, 'order', 'sev'), array_keys($order_keys))];
        $order_column[1] = $order_keys[Filters::enum(array_get($args, 'order2', 'sev'), array_keys($order_keys))];
        $sortorder  = sprintf('%s %s, %s %s, t.task_id ASC',
                $order_column[0], Filters::enum(array_get($args, 'sort', 'desc'), array('asc', 'desc')),
                $order_column[1], Filters::enum(array_get($args, 'sort2', 'desc'), array('asc', 'desc')));

        /// process search-conditions {{{
        $submits = array('type' => 'task_type', 'sev' => 'task_severity', 'due' => 'closedby_version', 'reported' => 'product_version',
                         'cat' => 'product_category', 'status' => 'item_status', 'percent' => 'percent_complete',
                         'dev' => array('a.user_id', 'us.user_name', 'us.real_name'),
                         'opened' => array('opened_by', 'uo.user_name', 'uo.real_name'),
                         'closed' => array('closed_by', 'uc.user_name', 'uc.real_name'));
        foreach ($submits as $key => $db_key) {
            $type = array_get($args, $key, ($key == 'status') ? 'open' : '');
            settype($type, 'array');
         
            if (in_array('', $type)) continue;
            
            if ($key == 'dev') {
                $from .= 'LEFT JOIN {assigned} a  ON t.task_id = a.task_id ';
                $from .= 'LEFT JOIN {users} us  ON a.user_id = us.user_id ';
            }
            
            $temp = '';
            foreach ($type as $val) {
                // add conditions for the status selection
                if ($key == 'status' && $val == 'closed') {
                    $temp  .= " is_closed = '1' AND";
                } elseif ($key == 'status') {
                    $temp .= " is_closed <> '1' AND";
                }
                if (is_numeric($val) && !is_array($db_key) && !($key == 'status' && $val == '8')) {
                    $temp .= ' ' . $db_key . ' = ?  OR';
                    $sql_params[] = $val;
                } elseif (is_array($db_key)) {
                    if ($key == 'dev' && ($val == 'notassigned' || $val == '0' || $val == '-1')) {
                        $temp .= ' a.user_id is NULL  OR';
                    } else {
                        if (!is_numeric($val)) $val = '%' . $val . '%';
                        foreach ($db_key as $value) {
                            $temp .= ' ' . $value . ' LIKE ?  OR';
                            $sql_params[] = $val;
                        }
                    }
                }
                
                // Add the subcategories to the query
                if ($key == 'cat') {
                    $result = $db->Query('SELECT  *
                                            FROM  {list_category}
                                           WHERE  category_id = ?',
                                          array($val));
                    $cat_details = $db->FetchRow($result);
                
                    $result = $db->Query('SELECT  *
                                            FROM  {list_category}
                                           WHERE  lft > ? AND rgt < ? AND project_id  = ?',
                                           array($cat_details['lft'], $cat_details['rgt'], $cat_details['project_id']));
                    while ($row = $db->FetchRow($result)) {
                        $temp  .= ' product_category = ?  OR';
                        $sql_params[] = $row['category_id'];
                    }
                }
            }

            if ($temp) $where[] = '(' . substr($temp, 0, -3) . ')';
        }
        /// }}}

        $dates = array('duedate' => 'due_date', 'changed' => 'event_date',
                       'opened' => 'date_opened', 'closed' => 'date_closed');
        foreach ($dates as $post => $db_key) {
            if ($date = array_get($args, $post . 'from')) {
                $where[]      = '(' . $db_key . ' >= ?)';
                $sql_params[] = strtotime($date);
            }
            if ($date = array_get($args, $post . 'to')) {
                $where[]      = '(' . $db_key . ' <= ? AND ' . $db_key . ' > 0)';
                $sql_params[] = strtotime($date);
            }
        }

        if (array_get($args, 'string')) {
            $words = explode(' ', strtr(array_get($args, 'string'), '()', '  '));
            $comments = '';
            $where_temp = array();
            
            if (array_get($args, 'search_in_comments')) {
                $comments = 'OR c.comment_text LIKE ?';
            }
            
            foreach ($words as $word) {
                $word = '%' . str_replace('+', ' ', trim($word)) . '%';
                $where_temp[] = "(t.item_summary LIKE ? OR t.detailed_desc LIKE ? OR t.task_id LIKE ? $comments)";
                array_push($sql_params, $word, $word, $word);
                if (array_get($args, 'search_in_comments')) {
                    array_push($sql_params, $word);
                }
            }
              
            $where[] = '(' . implode( (array_get($args, 'search_for_all') ? ' AND ' : ' OR '), $where_temp) . ')';
        }

        if (array_get($args, 'only_watched')) {
            //join the notification table to get watched tasks
            $from        .= " LEFT JOIN {notifications} fsn ON t.task_id = fsn.task_id";
            $where[]      = 'fsn.user_id = ?';
            $sql_params[] = $user->id;
        }

        $where = (count($where)) ? 'WHERE '. join(' AND ', $where) : ''; 

        // Get the column names of table tasks for the group by statement
        if (!strcasecmp($conf['database']['dbtype'], 'pgsql')) {
             $groupby .= "p.project_title, p.project_is_active, lst.status_name, lt.tasktype_name,{$order_column[0]},{$order_column[1]}, lr.resolution_name, ";
        }
        $groupby .= $db->GetColumnNames('{tasks}', 't.task_id', 't.');

        $sql = $db->Query("
                          SELECT   t.*, $select
                                   p.project_title, p.project_is_active,
                                   lst.status_name AS status_name,
                                   lt.tasktype_name AS task_type,
                                   lr.resolution_name
                          FROM     $from
                          $where 
                          GROUP BY $groupby
                          ORDER BY $sortorder", $sql_params);
        
        $tasks = $db->fetchAllArray($sql);
        $id_list = array();
        $limit = array_get($args, 'limit', -1);
        $task_count = 0;
        foreach ($tasks as $key => $task) {
            $id_list[] = $task['task_id'];
            if (!$user->can_view_task($task)) {
                unset($tasks[$key]);
                array_pop($id_list);
                --$task_count;
            } elseif (!is_null($perpage) && ($task_count < $offset || ($task_count > $offset - 1 + $perpage) || ($limit > 0 && $task_count >= $limit))) {
                unset($tasks[$key]);
            }
            
            ++$task_count;
        }

        return array($tasks, $id_list);
    }

}
?>