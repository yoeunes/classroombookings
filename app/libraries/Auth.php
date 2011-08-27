<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/*
 * Classroombookings. Hassle-free resource booking for schools. <http://classroombookings.com/>
 * Copyright (C) 2006-2011 Craig A Rodway <craig.rodway@gmail.com>
 *
 * This file is part of Classroombookings.
 * Classroombookings is licensed under the Affero GNU GPLv3 license.
 * Please see license-classroombookings.txt for the full license text.
 */
 
class Auth
{


	var $CI;
	var $dbuser;
	var $lasterr;
	var $cookiesalt;
	var $levels;
	var $settings;
	var $errpage;
	
	var $user_id;
	var $room_id;


	public function __construct(){
		
		// Load original CI object to global CI variable
		$this->CI =& get_instance();
		
		// Load helpers/models required by the library
		$this->CI->load->helper('cookie');
		$this->CI->load->model('security_model');
		$this->CI->load->library('user_agent');
		$this->CI->load->library('msg');
		
		#$this->CI->config->set_item('cookie_prefix', $_SERVER['SERVER_NAME']);
		
		// Cookie salt for hash - can be any text string
		$this->cookiesalt = 'CL455R00Mb00k1ng5'.$_SERVER['SERVER_NAME'];
		
	}
	
	
	
	
	/**
	 * Auth check function to see if a user has the appropriate privileges
	 *
	 * @param action The action the user is wanting to perform
	 * @param return TRUE: Just return the boolean answer. FALSE: Redirect/show error page/stop execution
	 */
	function check($action, $return = FALSE){
		
		log_message('debug', 'Auth: check(): Action - ' . $action . '.');
		
		// Get group ID
		$group_id = $this->CI->session->userdata('group_id');
		
		
		//$group_id = ($group_id == FALSE) ? 0 : $group_id;
		
		// Hopefully speed up access by putting the group permissions into the session
		// instead of additional DB lookups each time we run the check() function.
		if(!$this->CI->session->userdata('permissions')){
			// Get the group permissions for the user's group
			$group_permissions = $this->CI->security_model->get_group_permissions($group_id);
			$this->CI->session->set_userdata('permissions', $group_permissions);
		} else {
			$group_permissions = $this->CI->session->userdata('permissions');
		}
		
		// See if this action is in the permissions array for the user
		if(is_array($group_permissions)){
			$check = in_array($action, $group_permissions);
		} else {
			$check = FALSE;
		}
		
		log_message('debug', 'Auth: check(): Result: ' . $check . '.');
		
		// Return true/false if we only want the return value
		if($return == TRUE){
			return ($check == FALSE) ? FALSE : TRUE;
		}
		// Otherwise, error if failed check ...
		
		if($check == FALSE){
			
			// User is not allowed for that action - do stuff
			
			// Get the URI string they requested so can redirect to it after successful login
			$this->CI->session->set_userdata('uri', $this->CI->uri->uri_string());
			
			$this->CI->load->library('user_agent');
			
			// User logged in? If not then they must at least login.
			// If yes, then they just don't have the necessary privileges.
			if($this->logged_in() == FALSE){
				$this->lasterr = $this->CI->lang->line('AUTH_MUST_LOGIN');
				$this->lasterr2 = anchor('account/login', 'Click here to login.');
			} else {
				$this->lasterr = $this->CI->lang->line('AUTH_NO_PRIVS');
				$this->lasterr2 = anchor($this->CI->agent->referrer(), 'Click here to go back.');
			}
			
			$error =& load_class('Exceptions');
			echo $error->show_error($this->lasterr, $this->lasterr2);
			exit;
			
		}
		
	}
	
	
	
	
	/**
	 * Check a room permission for set user and room ID. (set_user(), set_room())
	 */
	function check_room($permission, $room_id = NULL){
		
		// If room ID supplied as parameter, use it instead
		$room_id = ($room_id != NULL) ? $room_id : $this->room_id;
		
		if(!is_numeric($room_id)){
			$this->lasterr = 'Room ID has not been set.';
			return FALSE;
		}
		
		if(!is_numeric($this->user_id)){
			$this->lasterr = 'User ID has not been set.';
			return FALSE;
		}
		
		// Get permissions on given room for the user. TRUE if user is exempt, otherwise array.
		$perms = $this->CI->rooms_model->permission_check($this->user_id, $this->room_id);
		
		// Allowed
		$cando = array();
		
		if(is_array($perms)){
			foreach($perms as $p){
				$cando[] = $p[1];
			}
			$perms = $cando;
		}
		
		// Finally complete the check
		$check = (is_array($perms)) ? in_array($permission, $perms) : $perms;
		
		return $check;
		
	}
	
	
	
	
	/**
	 * Return array of all permissions that the user has on the set room
	 */
	function room_permissions(){
		
		$perms = $this->CI->rooms_model->permission_check($this->user_id, $this->room_id);
		
		// Allowed
		$cando = array();

		if(is_array($perms)){
			foreach($perms as $p){
				$cando[] = $p[1];
			}
			$perms = $cando;
		}
		
		return $perms;
		
	}
	
	
	
	
	/**
	 * Set the class instance variable User ID
	 */
	function set_user($user_id = NULL){
		if($user_id == NULL){
			$user_id = $this->session->userdata('user_id');
		}
		$this->user_id = $user_id;
	}
	
	
	
	
	/**
	 * Set the class instance variable room ID
	 */
	function set_room($room_id){
		if(is_numeric($room_id)){
			$this->room_id = $room_id;
		}
	}
	
	
	
	
	/**
	 * Login user via a cookie
	 *
	 * Cookie key is stored in DB against a user and is selected to retrieve the user info.
	 * It is then passed to the login() function
	 *
	 * @param	string	key		Cookie key which should be a SHA1 hash
	 * @return	bool
	 */
	function cookielogin($key = NULL){
		// Check to see if key was supplied
		if($key == NULL){
			
			// No cookie key supplied, fatal!
			$this->lasterr = $this->CI->load->view('msg/err', 'Error with login cookie.', TRUE);
			$ret = FALSE;
			
		} else {
		
			// Got a key, now to see if it is correct format.
			
			if(strlen($key) != 40){
				
				// Is not valid key
				$this->lasterr = $this->CI->msg->err('Cookie is not the correct length.');
				$ret = FALSE;
				
			} else {
				
				// Got cookie key! hopefully should be in the DB
				$sql = 'SELECT user_id, username, lastlogin 
						FROM users 
						WHERE cookiekey = ? 
						LIMIT 1';
				// Run query
				$query = $this->CI->db->query($sql, array($key));
				
				// Check to see how many rows we got from selecting via the cookie key
				if($query->num_rows() == 1){
					
					// Ok, got user!
					$user = $query->row();
					
					// Generate original cookie key hash (what we *expect* it to be, if valid) to compare to
					$cookiekey = sha1(implode("", array(
						$this->cookiesalt,
						$user->user_id, 
						$user->username,
						$user->lastlogin,
						$this->CI->agent->agent_string(),
					)));
					
					// Compare hash
					if($cookiekey == $key){
						
						// Matched! We can now log the user in and set the remember-me option again
						//$login = $this->login($userinfo->username, $userinfo->password, TRUE);
						$login = $this->session_create($user->username, TRUE);
						$ret = $login;
						
					} else {
						
						// Did not match!
						$this->lasterr = $this->CI->msg->err("Invalid cookie (did not match database entry). Did you log in from another computer?");	//<br />Compare $cookiekey to $key.");
						$ret = FALSE;
						
					}
					
				} else {
					
					// No rows returned from the DB with that cookie key
					$this->lasterr = $this->CI->msg->err('Could not find your cookie in the database. Did you log in from another computer?');
					$ret = FALSE;
					
				}		// End of num_rows() check
				
			}		// End of strlen() check on cookie key
			
		}		// End of key == NULL check
		
		// Check the return value
		if($ret == FALSE){
			
			// Remove cookies - they're useless now
			// If we kept them, CRBS would keep using them to login and we would be in an endless loop...
			delete_cookie("crbs_key");
			delete_cookie("crbs_user_id");
			
			// This code has now been moved to the Msg library
			/* $error =& load_class('Exceptions');
			echo $error->show_error("Cannot login via cookie", $this->lasterr);
			exit; */
			
			// Generate and show error with link to login page
			$this->lasterr .= '<br />' . anchor('account/login', 'Click here to login using your username and password.');			
			$this->CI->msg->fail('Cannot login via cookie', $this->lasterr);
			
		} else {
			
			return $ret;
			
		}
		
	}
	
	
	
	
	/**
	 * Function to authenticate a user in the database
	 * Also sets session data and cookie if required
	 *
	 * @param	string	username	Username
	 * @param	string	password	Password in either sha1 or plaintext
	 * @param	bool	remember	Whether or not to set the remember cookie (default is false)
	 * @param	bool	is_sha1		Is password already sha1
	 * @return	bool
	 */
	function login($username, $password, $remember = FALSE, $is_sha1 = FALSE){
		
		// Retrieve auth settings
		$auth = $this->CI->settings->get('auth.');
		$ldap = ($auth['auth.ldap'] == 1);
		
		if($username != NULL && $password != NULL){
			
			$trylocal = TRUE;
			
			// See if user we're trying to authenticate is local or LDAP
			$sql = 'SELECT ldap FROM users WHERE username = ? LIMIT 1';
			$query = $this->CI->db->query($sql, array($username));
			if($query->num_rows() == 1){
				// Found the user, so we know they exist.
				$row = $query->row();
				$userldap = $row->ldap;
				$tryldap = ($userldap == 1);
			} else {
				// User doesn't exist at all! Try LDAP as that will create accounts automatically
				$tryldap = TRUE;
			}
			
			
			// Check if we are using LDAP/AD auth or not and also if we should try authing this user via ldap
			if($ldap == TRUE && $tryldap == TRUE){
				
				// Don't try local auth unless this fails
				$trylocal = FALSE;
				
				// We are using LDAP. First, send the supplied user and password to the ldap function
				$ldapauth = $this->auth_ldap($username, $password);
				
				if($ldapauth == TRUE){
					return $this->session_create($username, $remember);
				} else {
					// Fail if the LDAP auth function failed (lasterr is already set by that function)
					#$trylocal = TRUE;
					return FALSE;
				}
				
			} elseif($ldap == FALSE && $tryldap == TRUE){
				
				$this->lasterr = 'User authenticates via LDAP but system is not configured to use LDAP.';
				return FALSE;
				
			}
			
			// Not using LDAP or LDAP auth failed, so we look up a local user in the DB (trylocal should be TRUE now)
			
			if($trylocal == TRUE){
				
				$localauth = $this->auth_local($username, $password, $is_sha1);
				
				if($localauth == TRUE){
					return $this->session_create($username, $remember);
				} else {
					$this->lasterr = (isset($this->lasterr)) ? $this->lasterr : "Incorrect username and/or password";
				}
				
			}
			
		} else {
			
			// No username and password supplied
			$this->lasterr = "No username and/or password supplied to Auth library.";
			return FALSE;
			
		}
		
	}
	
	
	
	
	/**
	 * Create login session
	 *
	 * This function should only be called once the user has been validated via ldap/local/preauth.
	 * The user MUST exist.
	 *
	 * @param string username
	 * @return bool
	 */
	function session_create($username, $remember = FALSE){
		
		/*
			We need to check of the enabled=1 at this stage because we dont want 
			preauth/ldap/local to override this
		*/
		$sql = 'SELECT 
					user_id, 
					group_id, 
					username, 
					displayname,
					IFNULL(displayname, username) AS display
				FROM users
				WHERE username = ? 
				AND enabled = 1 
				LIMIT 1';
		$query = $this->CI->db->query($sql, array($username));
		
		if($query->num_rows() == 1){
			
			// Cool, got the user we wanted
			$user = $query->row();
			
			// Update the DB's last login time (now)..
			$timestamp = mdate('%Y-%m-%d %H:%i:%s');
			$sql = 'UPDATE users 
					SET lastlogin = ? 
					WHERE user_id = ?';
			$this->CI->db->query($sql, array($timestamp, $user->user_id));
			
			// Create session data array
			$sessdata['user_id']			= $user->user_id;
			$sessdata['group_id']			= $user->group_id;
			$sessdata['username']			= $user->username;
			$sessdata['display']			= $user->display;	#($user->display == NULL) ? $user->username : $user->display;
			$sessdata['year_active']		= $this->CI->years_model->get_active_id();
			$sessdata['year_working']		= $sessdata['year_active'];
			$sessdata['group_permissions']	= $this->CI->security_model->get_group_permissions($user->group_id);
			$sessdata['is_anon']			= false;
			
			// Set session data
			$this->CI->session->set_userdata($sessdata);
			
			// Now set remember-me cookie if requested
			if($remember == TRUE){
				// Generate hash using details we just retrieved
				$cookiekey = sha1(implode("", array(
					$this->cookiesalt,
					$user->user_id, 
					$user->username,
					$timestamp,
					$this->CI->agent->agent_string(),
				)));
				
				// Set cookie data
				$cookie['expire'] = 60 * 60 * 24 * 14;		// 14 days
				
				$cookie['name'] = 'crbs_key';
				$cookie['value'] = $cookiekey;
				set_cookie($cookie);
				$cookie['name'] = 'crbs_user_id';
				$cookie['value'] = $user->user_id;
				set_cookie($cookie);
				
				// Update DB table with the hash that we will later check on return visit
				$sql = 'UPDATE users 
						SET cookiekey = ? 
						WHERE user_id = ?';
				$query = $this->CI->db->query($sql, array($cookiekey, $user->user_id));
			}
			
			// Delete some cookies that might have been left over that we don't want
			delete_cookie("cal_month");
			delete_cookie("cal_year");
			
			// Done all we needed to do.
			// TODO: Should we check the session data has actually been set before returning success?
			return TRUE;
			
		} else {
			
			// FAIL! User account is *probably*: 1) LDAP, but 2) Disabled
			$this->lasterr = 'Logon failed - could not find details to initialise session.';
			return FALSE;
			
		}
		
	}
	
	
	
	
	function session_create_anon()
	{
		$anon_user_id = $this->CI->settings->get('auth_anonuserid');
		if (empty($anon_user_id))
		{
			$this->lasterr = 'No anonymous user has been configured.';
			log_message('debug', 'Auth: session_create_anon(): ' . $this->lasterr);
			return false;
		}
		
		$sql = 'SELECT 
					user_id, 
					group_id, 
					username, 
					displayname,
					IFNULL(displayname, username) AS display
				FROM users
				WHERE user_id = ? 
				AND enabled = 1 
				LIMIT 1';
		$query = $this->CI->db->query($sql, array($anon_user_id));
		
		if($query->num_rows() == 1){

			// Cool, got the user we wanted
			$user = $query->row();
			
			// Create session data array
			$sessdata['user_id']			= $user->user_id;
			$sessdata['group_id']			= $user->group_id;
			$sessdata['username']			= $user->username;
			$sessdata['display']			= $user->display;
			$sessdata['year_active']		= $this->CI->years_model->get_active_id();
			$sessdata['year_working']		= $sessdata['year_active'];
			$sessdata['permissions']		= $this->CI->security_model->get_group_permissions($user->group_id);
			$sessdata['is_anon']			= true;

			// Set session data
			$this->CI->session->set_userdata($sessdata);
			
		}
		else
		{
			$this->lasterr = 'Anonymous user not found in database.';
			return false;
		}
		
	}
	
	
	
	
	/**
	 * Logout function that clears all the session data and destroys it
	 *
	 * @return	bool
	 */	 	
	function logout(){
		
		$user_id = $this->CI->session->userdata('user_id');
		
		$sql = 'DELETE FROM usersactive WHERE user_id = ?';
		$query = $this->CI->db->query($sql, array($user_id));
		
		// Set session data to NULL (include all fields!)
		$sessdata['user_id'] = NULL;
		$sessdata['group_id'] = NULL;
		$sessdata['username'] = NULL;
		$sessdata['display'] = NULL;
		$sessdata['group_permissions'] = NULL;
		
		// Set empty session data
		$this->CI->session->unset_userdata($sessdata);
		
		// Destroy session
		@$this->CI->session->sess_destroy();
		
		// Remove cookies too
		delete_cookie("crbs_key");
		delete_cookie("crbs_user_id");
		delete_cookie("crbsb.room_id");
		delete_cookie("crbsb.week");
		delete_cookie("crbsb.week_requested_date");
		delete_cookie("tab.bookings");
		
		// NULLify the cookie key in the DB
		//$sql = 'UPDATE users SET cookiekey = NULL WHERE user_id = ?';
		//$query = $this->CI->db->query($sql, array($user_id));
		
		// Verify session has been destroyed by retrieving info 
		return ($this->CI->session->userdata('user_id') == FALSE) ? TRUE : FALSE;
		
	}
	
	
	
	
	/**
	 * Pre-authentication handling feature
	 *
	 * @param array Data array. Must contain keys and values of: username, timestamp, preauth
	 */
	function preauth($data){
		
		// Check for username
		if(!isset($data['username'])){
			$this->lasterr = 'No username supplied.';
			return FALSE;
		}
		if(!isset($data['timestamp'])){
			$this->lasterr = 'No timestamp supplied.';
			return FALSE;
		}
		if(!isset($data['preauth'])){
			$this->lasterr = 'No computed preauth supplied.';
			return FALSE;
		}
		
		// Work out current time and the tolerances/threshold
		$timestamp = now();
		$time_lower = strtotime("-5 minutes");
		$time_upper = strtotime("+5 minutes");
		
		// Check if the supplied timestamp is within the allowed threshold
		if( ($data['timestamp'] < $time_lower) OR ($data['timestamp'] > $time_upper) ){
			$this->lasterr = 'Supplied timestamp falls outside of the allowed threshold of 5 minutes.';
			return FALSE;
		}
		
		// Get the current key from the database
		$preauthkey = $this->CI->settings->get('auth.preauth.key');
		
		// Work out what we *should* get based on their info + our preauthkey
		$expected_final = sha1("{$data['username']}|{$data['timestamp']}|{$preauthkey}");
		
		// Finally we compare our correct result with their result
		$compare = ($expected_final == $data['preauth']);
		
		if($compare == FALSE){ $this->lasterr = 'Key did not match the expected value.'; }
		
		return $compare;
		
	}
	
	
	
	
	/**
	 * Local authentication function
	 *
	 * Simply checks if a local user's password is valid
	 *
	 * @param	string	username
	 * @param	string	password
	 * @param	bool	is_sha1		Specifies whether the password is already an SHA1 hash
	 * @return	bool
	 */
	function auth_local($username, $password, $is_sha1 = FALSE){
		
		// Check if we need to SHA1 the supplied password
		$password = ($is_sha1 == FALSE) ? sha1($password) : $password;
		
		$sql = 'SELECT user_id, username
				FROM users
				WHERE username = ?
				AND password = ?
				AND enabled = 1
				AND ldap = 0
				LIMIT 1';
				
		
		$query = $this->CI->db->query($sql, array($username, $password));
		
		if($query->num_rows() == 1){
			
			// Success
			return TRUE;
			
		} else {
			
			// Fail
			$this->lasterr = 'Local authentication failure. Incorrect username and/or password.';
			
		}
		
	}
	
	
	
	
	/**
	 * LDAP authenticate function
	 *
	 * Checks the configured LDAP server for valid supplied credentiales.
	 * Optionally will update local DB with LDAP display/email info.
	 * This should not be called for users who authenticate locally.
	 *
	 * @param	string	username
	 * @param	string	password
	 * @param	bool	updateinfo		Update the local DB with info from LDAP or not
	 * @return	mixed	local user_id on success, FALSE on failure
	 */
	function auth_ldap($username, $password){
		
		if(!function_exists('ldap_bind')){
			$this->lasterr = 'It appears that the PHP LDAP module is not installed - cannot continue.';
			return FALSE;
		}
		
		// Retrieve auth settings
		$auth = $this->CI->settings->get('auth.');
		
		// See if the user exists at all
		$userexists = $this->userexists($username);
		
		// Set values
		$ldaphost = $auth->ldaphost;
		$ldapport = $auth->ldapport;
		$ldapbase = $auth->ldapbase;
		$ldapfilter = str_replace("%u", $username, $auth->ldapfilter);
		$ldaploginupdate = ($auth->ldaploginupdate == 1) ? TRUE : FALSE;
		$ldapusername = 'cn=' . $username;
		
		// Attempt connection to server
		$connect = ldap_connect($ldaphost, $ldapport);
		if(!$connect){
			$this->lasterr = sprintf('Failed to connect to LDAP server %s on port %d.', $ldaphost, $ldapport);
			return FALSE;
		}
		
		// Now go through the DNs and see if we can bind as the user in them
		$dns = explode(";", $ldapbase);
		$found = FALSE;
		foreach($dns as $dn){
			if($found == FALSE){
				$thisdn = trim($dn);
				$bind = @ldap_bind($connect, "$ldapusername,$thisdn", $password);
				if($bind){ 
					$correctdn = $thisdn;
					$found = TRUE;
				}
			}
		}
		
		// Check if user in a DN has been found
		if($found == FALSE){
			// Password could be wrong.
			$this->lasterr = 'LDAP authentication failure. Check details and try again.';
			return FALSE;
		}
		
		// search for details
		$search = ldap_search($connect, $correctdn, $ldapfilter);
		if(!$search){
			// LDAP query filter is probably incorrect.
			$this->lasterr = "LDAP authentication failure. Query filter did not return any results.";
			return FALSE;
		}
		
		// Get info
		$info = ldap_get_entries($connect, $search); 
		$user['username'] = $username;
		$user['displayname'] = $info[0]['displayname'][0];
		$user['email'] = $info[0]['mail'][0];
		$user['memberof'] = $info[0]['memberof'];
		$user['group_ids'] = array();
		
		// Succeeded with all info
		
		// If user already exists and we don't want to update at login, complete the auth now.
		if($userexists == TRUE && $ldaploginupdate == FALSE){
			return TRUE;
		}
		
		
		/*
			... otherwise, add if they dont exist; and update if they do.
			... either way, we need to fetch some data. Do that now...
		*/
		
		// Get group mappings
		unset($info[0]['memberof']['count']);
		// Mapping of ldapgroupnames => localgroupid
		$groupmap = $this->CI->security->ldap_groupname_to_group();
		// Make new array to hold the group names that the user belongs to
		$groups = array();		
		
		// iterate the groups they are member of to find potential local group
		foreach($info[0]['memberof'] as $group){
			// We only need the CN= part
			$grouparray = explode(',', $group);
			$group = str_replace('CN=', '', $grouparray[0]);
			if(array_key_exists($group, $groupmap)){
				// Put possible group IDs into an array
				array_push($user['group_ids'], $groupmap[$group]);
			}
			// Stick this group into the group array
			array_push($groups, $group);
		}
		
		// Remove any duplicates (not sure this actually has a purpose as they should all be unique anyway)
		$user['group_ids'] = array_unique($user['group_ids']);	#, SORT_NUMERIC);
		
		// LDAP-TO-LOCAL: Find departments (using the previously-populated array)
		$user['department_ids'] = $this->CI->security->ldap_groupnames_to_departments($groups);
		
		// Now the data array that has all correct info for sending to the DB
		
		// Set group ID of user (to the ldap mapping if unique, otherwise the default)
		$data['group_id'] = (count($user['group_ids']) == 1) ? $user['group_ids'][0] : $auth->ldapgroup_id;
		// Find departments we should assign the user to
		$data['departments'] = $user['department_ids'];		
		// Now the array of info for the user adding function
		$data['username'] = $user['username'];
		$data['displayname'] = (isset($user['displayname']) OR $user['displayname'] != '') ? $user['displayname'] : $user['username'];
		$data['email'] = $user['email'];
		$data['ldap'] = 1;
		$data['password'] = NULL;
		
		
		/*
			At this point, we have authenticated and we need to know if we should update
			user details or add them as a new user.
		*/
		
		if($userexists == TRUE){
			
			// Already in
			
			$sql = 'SELECT user_id FROM users WHERE username = ? LIMIT 1';
			$query = $this->CI->db->query($sql, array($username));
			$row = $query->row();
			$user_id = $row->user_id;
			
			// We should only get here if loginupdate is true anyway, but here goes...
			if($ldaploginupdate == TRUE){
				$edit = $this->CI->security->edit_user($user_id, $data);
				if($edit == FALSE){
					$this->lasterr = $this->CI->security->lasterr;
					return FALSE;
				}
			} else {
				$this->lasterr = 'Expected ldaploginupdate to be TRUE but got FALSE instead';
				return FALSE;
			}
			
		} elseif($userexists == FALSE){
			
			// Add
			$data['enabled'] = 1;
			$add = $this->CI->security->add_user($data);
			if($add == FALSE){
				$this->lasterr = $this->CI->security->lasterr;
				return FALSE;
			}
			
		}
		
		return TRUE;
		
	}
	
	
	
	
	/**
	 * Check if a user exists
	 *
	 * @param string Username to check
	 * @return bool
	 */
	function userexists($username){
		$sql = 'SELECT user_id FROM users WHERE username = ? LIMIT 1';
		$query = $this->CI->db->query($sql, array($username));
		return ($query->num_rows() == 1) ? TRUE : FALSE;
	}
	
	
	
	
	/**
	 * Check if an email address is already used in the DB for any user
	 *
	 * @param string Email address to look up
	 * @return bool
	 */
	function emailexists($email){
		$sql = 'SELECT uid FROM userinfo WHERE email = ? LIMIT 1';
		$query = $this->dbuser->query($sql, array($email));
		return ($query->num_rows() == 1) ? TRUE : FALSE;
	}
	
	
	
	
	/**
	 * Return if user is logged in or not
	 */
	function logged_in()
	{
		$user_id = $this->CI->session->userdata('user_id');
		return ($user_id && !$this->is_anon());
	}
	
	
	
	function is_anon()
	{
		$anon_user_id = $this->CI->settings->get('auth_anonuserid');
		$session_user_id = $this->CI->session->userdata('user_id');
		return ($session_user_id == $anon_user_id);
	}
	
	
	
	
	function active_users(){
		
		$sql = 'SELECT users.user_id, users.username, users.displayname, usersactive.timestamp
				FROM users
				RIGHT JOIN usersactive ON users.user_id = usersactive.user_id';
		$query = $this->CI->db->query($sql);
		
		$result = $query->result();
		$activeusers = array();
		
		foreach($result as $user){
			$display = ($user->displayname != '' OR $user->displayname != NULL) ? $user->displayname : $user->username;
			//array_push($activeusers, $display);
			$activeusers[$user->user_id] = $display;
		}
		
		return $activeusers;
		
	}
	
	
	
	
}




/* End of file app/libraries/Auth.php */