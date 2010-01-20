#!/usr/bin/php
<?php
/**
 * This script imports SquirrelMail database addressbooks into Turba.
 *
 * The first argument must be a DSN to the database containing the "address"
 * table, e.g.: "mysql://root:password@localhost/squirrelmail".
 *
 * Copyright 2008-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (ASL).  If you
 * did not receive this file, see http://www.horde.org/licenses/asl.php.
 *
 * @author Ben Chavet <ben@horde.org>
 * @author Jan Schneider <jan@horde.org>
 */

// Do CLI checks and environment setup first.
require_once dirname(__FILE__) . '/../lib/Application.php';
Horde_Registry::appInit('turba', array('authentication' => 'none', 'cli' => true, 'user' => $conf['auth']['admins'] ? $conf['auth']['admins'][0] : null));

require_once TURBA_BASE . '/lib/Object/Group.php';

// Read command line parameters.
$cli = Horde_Cli::singleton();
if ($argc != 2) {
    $cli->message('Too many or too few parameters.', 'cli.error');
    $cli->writeln('Usage: import_squirrelmail_file_abook.php DSN');
    exit;
}
$dsn = $argv[1];

// Connect to database.
$db = DB::connect($dsn);
if (is_a($db, 'PEAR_Error')) {
    $cli->fatal($db->toString());
}

// Loop through SquirrelMail address books.
$handle = $db->query('SELECT owner, nickname, firstname, lastname, email, label FROM address ORDER BY owner');
if (is_a($handle, 'PEAR_Error')) {
    $cli->fatal($handle->toString());
}
$turba_shares = Horde_Share::singleton('turba');
$user = null;
$count = 0;
while ($row = $handle->fetchRow(DB_FETCHMODE_ASSOC)) {
    // Set current user
    if ($row['owner'] != $user) {
        if (!is_null($user)) {
            $cli->message('  Added ' . $count . ' contacts', 'cli.success');
            $count = 0;
        }
        $user = $row['owner'];
        Horde_Auth::setAuth($user, array());
        $cli->message('Importing ' . $user . '\'s address book');

        // Reset user prefs
        unset($prefs);
        $prefs = Horde_Prefs::factory($conf['prefs']['driver'], 'turba', $user, null, null, false);

        // Reset $cfgSources for current user.
        unset($cfgSources);
        $hasShares = false;
        include TURBA_BASE . '/config/sources.php';
        foreach ($cfgSources as $key => $cfg) {
            if (!empty($cfg['use_shares'])) {
                $has_share = true;
                break;
            }
        }
        if ($has_share) {
            $cfgSources = Turba::getConfigFromShares($cfgSources);
        }
        $cfgSources = Turba::permissionsFilter($cfgSources);
        if (!count($cfgSources)) {
            $cli->message('No address book available for ' . $user, 'cli.error');
            continue;
        }

        // Get user's default addressbook
        $import_source = $prefs->getValue('default_dir');
        if (empty($import_source)) {
            $import_source = array_keys($cfgSources);
            $import_source = $import_source[0];
        }

        // Check existance of the specified source.
        if (!isset($cfgSources[$import_source])) {
            $cli->message('  ' . sprintf(_("Invalid address book: %s"), $import_source), 'cli.error');
            continue;
        }

        // Initiate driver
        $driver = &Turba_Driver::singleton($import_source);
        if (is_a($driver, 'PEAR_Error')) {
            $cli->message('  ' . sprintf(_("Connection failed: %s"), $driver->getMessage()), 'cli.error');
            continue;
        }
    }

    if (!count($cfgSources)) {
        continue;
    }

    $members = Horde_Mime_Address::explode($row['email'], ',;');
    if (count($members) > 1) {
        // Entry is a list of contacts, import each individually and create a
        // group that contains them.
        $attributes = array('alias' => $row['nickname'],
                            'firstname' => $row['firstname'],
                            'lastname' => $row['lastname'],
                            'notes' => $row['label']);
        $gid = $driver->add($attributes);
        $group = new Turba_Object_Group($driver, array_merge($attributes, array('__key' => $gid)));
        $count++;
        foreach ($members as $member) {
            $result = $driver->add(array('firstname' => $member, 'email' => $member));
            if ($result && !is_a($result, 'PEAR_Error')) {
                $added = $group->addMember($result, $import_source);
                if (is_a($added, 'PEAR_Error')) {
                    $cli->message('  ' . $added->getMessage(), 'cli.error');
                } else {
                    $count++;
                }
            }
        }
        $group->store();
    } else {
        // Entry only contains one contact, import it.
        $contact = array('alias' => $row['nickname'],
                         'firstname' => $row['firstname'],
                         'lastname' => $row['lastname'],
                         'email' => $row['email'],
                         'notes' => $row['label']);
        $added = $driver->add($contact);
        if (is_a($added, 'PEAR_Error')) {
            $cli->message('  ' . $added->getMessage(), 'cli.error');
        } else {
            $count++;
        }
    }
}
$cli->message('  Added ' . $count . ' contacts', 'cli.success');
