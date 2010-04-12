<?php
/**
 * Turba application API.
 *
 * This file defines Horde's core API interface. Other core Horde libraries
 * can interact with Turba through this API.
 *
 * Copyright 2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @package Turba
 */

/* Determine the base directories. */
if (!defined('TURBA_BASE')) {
    define('TURBA_BASE', dirname(__FILE__) . '/..');
}

if (!defined('HORDE_BASE')) {
    /* If Horde does not live directly under the app directory, the HORDE_BASE
     * constant should be defined in config/horde.local.php. */
    if (file_exists(TURBA_BASE . '/config/horde.local.php')) {
        include TURBA_BASE . '/config/horde.local.php';
    } else {
        define('HORDE_BASE', TURBA_BASE . '/..');
    }
}

/* Load the Horde Framework core (needed to autoload
 * Horde_Registry_Application::). */
require_once HORDE_BASE . '/lib/core.php';

class Turba_Application extends Horde_Registry_Application
{
    /**
     * The application's version.
     *
     * @var string
     */
    public $version = 'H4 (3.0-git)';

    /**
     * Initialization.
     *
     * Global variables defined:
     *   $addSources   - TODO
     *   $attributes   - TODO
     *   $browse_source_count - TODO
     *   $browse_source_options - TODO
     *   $cfgSources   - TODO
     *   $copymove_source_options - TODO
     *   $copymoveSources - TODO
     *   $turba_shares - TODO
     */
    protected function _init()
    {
        // Turba source and attribute configuration.
        include TURBA_BASE . '/config/attributes.php';
        include TURBA_BASE . '/config/sources.php';

        /* UGLY UGLY UGLY - we should NOT be using this as a global
         * variable all over the place. */
        $GLOBALS['cfgSources'] = &$cfgSources;

        // See if any of our sources are configured to use Horde_Share.
        foreach ($cfgSources as $key => $cfg) {
            if (!empty($cfg['use_shares'])) {
                // Create a share instance.
                $_SESSION['turba']['has_share'] = true;
                $GLOBALS['turba_shares'] = Horde_Share::singleton('turba');
                $cfgSources = Turba::getConfigFromShares($cfgSources);
                break;
            }
        }

        $GLOBALS['attributes'] = $attributes;
        $cfgSources = Turba::permissionsFilter($cfgSources);

        // Build the directory sources select widget.
        $default_source = Horde_Util::nonInputVar('source');
        if (empty($default_source)) {
            $default_source = empty($_SESSION['turba']['source'])
                ? Turba::getDefaultAddressBook()
                : $_SESSION['turba']['source'];
            $default_source = Horde_Util::getFormData('source', $default_source);
        }

        $GLOBALS['browse_source_count'] = 0;
        $GLOBALS['browse_source_options'] = '';

        foreach (Turba::getAddressBooks() as $key => $curSource) {
            if (!empty($curSource['browse'])) {
                $selected = ($key == $default_source) ? ' selected="selected"' : '';
                $GLOBALS['browse_source_options'] .= '<option value="' . htmlspecialchars($key) . '" ' . $selected . '>' .
                    htmlspecialchars($curSource['title']) . '</option>';

                ++$GLOBALS['browse_source_count'];

                if (empty($default_source)) {
                    $default_source = $key;
                }
            }
        }

        if (empty($cfgSources[$default_source]['browse'])) {
            $default_source = Turba::getDefaultAddressBook();
        }
        $_SESSION['turba']['source'] = $default_source;
        $GLOBALS['default_source'] = $default_source;

        /* Only set $add_source_options if there is at least one editable
         * address book that is not the current address book. */
        $addSources = Turba::getAddressBooks(Horde_Perms::EDIT, array('require_add' => true));
        $copymove_source_options = '';
        $copymoveSources = $addSources;
        unset($copymoveSources[$default_source]);
        foreach ($copymoveSources as $key => $curSource) {
            if ($key != $default_source) {
                $copymove_source_options .= '<option value="' . htmlspecialchars($key) . '">' .
                    htmlspecialchars($curSource['title']) . '</option>';
            }
        }

        $GLOBALS['addSources'] = $addSources;
        $GLOBALS['copymove_source_options'] = $copymove_source_options;
        $GLOBALS['copymoveSources'] = $copymoveSources;
    }

    /**
     * Returns a list of available permissions.
     *
     * @return array  An array describing all available permissions.
     */
    public function perms()
    {
        static $perms = array();
        if (!empty($perms)) {
            return $perms;
        }

        require TURBA_BASE . '/config/sources.php';

        $perms['tree']['turba']['sources'] = false;
        $perms['title']['turba:sources'] = _("Sources");

        // Run through every contact source.
        foreach ($cfgSources as $source => $curSource) {
            $perms['tree']['turba']['sources'][$source] = false;
            $perms['title']['turba:sources:' . $source] = $curSource['title'];
            $perms['tree']['turba']['sources'][$source]['max_contacts'] = false;
            $perms['title']['turba:sources:' . $source . ':max_contacts'] = _("Maximum Number of Contacts");
            $perms['type']['turba:sources:' . $source . ':max_contacts'] = 'int';
        }

        return $perms;
    }

    /**
     * Code to run on init when viewing prefs for this application.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    public function prefsInit($ui)
    {
        global $prefs;

        switch ($ui->group) {
        case 'addressbooks':
            Horde_Core_Prefs_Ui_Widgets::sourceInit();

            if (!$prefs->isLocked('default_dir')) {
                $out = array();
                foreach ($GLOBALS['cfgSources'] as $key => $info) {
                    $out[$key] = $info['title'];
                }
                $ui->override['default_dir'] = $out;
            }

            $out = array();
            foreach (Turba::getAddressBooks() as $key => $curSource) {
                if (empty($curSource['map']['__uid'])) {
                    continue;
                }
                if (!empty($curSource['browse'])) {
                    $out[$key] = $curSource['title'];
                }
                $sync_books = @unserialize($prefs->getValue('sync_books'));
                if (empty($sync_books)) {
                    $prefs->setValue('sync_books', serialize(array(Turba::getDefaultAddressbook())));
                }
            }
            $ui->override['sync_books'] = $out;
            break;

        case 'columns':
            Horde::addScriptFile('effects.js', 'horde');
            Horde::addScriptFile('dragdrop.js', 'horde');
            Horde::addScriptFile('columnprefs.js', 'turba');
            break;
        }
    }

    /**
     * Generate code used to display a special preference.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     * @param string $item             The preference name.
     *
     * @return string  The HTML code to display on the options page.
     */
    public function prefsSpecial($ui, $item)
    {
        switch ($item) {
        case 'addressbookselect':
            $order = Turba::getAddressBookOrder();
            $selected = $sorted = $unselected = array();

            foreach (array_keys($GLOBALS['cfgSources']) as $val) {
                if (isset($order[$val])) {
                    $sorted[intval($order[$val])] = $val;
                } else {
                    $unselected[$val] = $GLOBALS['cfgSources'][$val]['title'];
                }
            }

            foreach ($sorted as $val) {
                $selected[$val] = $GLOBALS['cfgSources'][$val]['title'];
            }

            return Horde_Core_Prefs_Ui_Widgets::source(array(
                'mainlabel' => _("Choose which address books to display, and in what order:"),
                'selectlabel' => _("These addressbooks will display in this order:"),
                'sources' => array(array(
                    'selected' => $selected,
                    'unselected' => $unselected
                )),
                'unselectlabel' => _("Address books that will not be displayed:")
            ));

        case 'columnselect':
            $sources = Turba::getColumns();

            $t = $GLOBALS['injector']->createInstance('Horde_Template');
            $t->setOption('gettext', true);

            $t->set('columns', htmlspecialchars($GLOBALS['prefs']->getValue('columns')));

            $col_list = $cols = array();
            foreach ($GLOBALS['cfgSources'] as $source => $info) {
                $col_list[] = array(
                    'first' => empty($col_list),
                    'source' => htmlspecialchars($source),
                    'title' => htmlspecialchars($info['title'])
                );

                // First the selected columns in their current order.
                $i = 0;
                $inputs = array();

                if (isset($sources[$source])) {
                    $selected = array_flip($sources[$source]);
                    foreach ($sources[$source] as $column) {
                        if ((substr($column, 0, 2) == '__') ||
                            ($column == 'name')) {
                            continue;
                        }

                        $inputs[] = array(
                            'checked' => isset($selected[$column]),
                            'column' => htmlspecialchars($column),
                            'i' => $i,
                            'label' => htmlspecialchars($GLOBALS['attributes'][$column]['label'])
                        );
                    }
                } else {
                    // Need to unset this for the loop below, otherwise
                    // selected columns from another source could interfere
                    unset($selected);
                }

                // Then the unselected columns in source order.
                foreach (array_keys($info['map']) as $column) {
                    if ((substr($column, 0, 2) == '__') ||
                        ($column == 'name') ||
                        isset($selected[$column])) {
                        continue;
                    }

                    $inputs[] = array(
                        'checked' => isset($selected[$column]),
                        'column' => htmlspecialchars($column),
                        'i' => $i,
                        'label' => htmlspecialchars($GLOBALS['attributes'][$column]['label'])
                    );
                }

                $cols[] = array(
                    'first' => empty($cols),
                    'inputs' => $inputs,
                    'source' => htmlspecialchars($source)
                );
            }

            if (!empty($col_list)) {
                $t->set('col_list', $col_list);
                $t->set('cols', $cols);
            }

            return $t->fetch(TURBA_TEMPLATES . '/prefs/column.html');
        }
    }

    /**
     * Special preferences handling on update.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     * @param string $item             The preference name.
     *
     * @return boolean  True if preference was updated.
     */
    public function prefsSpecialUpdate($ui, $item)
    {
        switch ($item) {
        case 'addressbookselect':
            $data = Horde_Core_Prefs_Ui_Widgets::sourceUpdate($ui);
            if (isset($data['sources'])) {
                $ui->setValue('addressbooks', $data['sources']);
                return true;
            }
            break;

        case 'columnselect':
            if (isset($ui->vars->columns)) {
                $ui->setValue('columns', $ui->vars->columns);
                return true;
            }
            break;
        }

        return false;
    }

    /**
     * Generate the menu to use on the prefs page.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return Horde_Menu  A Horde_Menu object.
     */
    public function prefsMenu($ui)
    {
        return Turba::getMenu();
    }

    /**
     * Removes user data.
     *
     * @param string $user  Name of user to remove data for.
     *
     * @return mixed  true on success | PEAR_Error on failure
     */
    public function removeUserData($user)
    {
        if (!Horde_Auth::isAdmin() && $user != Horde_Auth::getAuth()) {
            return PEAR::raiseError(_("You are not allowed to remove user data."));
        }

        /* We need a clean copy of the $cfgSources array here.*/
        require TURBA_BASE . '/config/sources.php';
        $hasError = false;

        foreach ($cfgSources as $source) {
            if (empty($source['use_shares'])) {
                // Shares not enabled for this source
                $driver = Turba_Driver::singleton($source);
                if (is_a($driver, 'PEAR_Error')) {
                    Horde::logMessage($driver, 'ERR');
                    $hasError = true;
                } else {
                    $result = $driver->removeUserData($user);
                    if (is_a($result, 'PEAR_Error')) {
                        Horde::logMessage($result, 'ERR');
                    }
                }
            }
        }

        /* Only attempt share removal if we have shares configured */
        if (!empty($_SESSION['turba']['has_share'])) {
            $shares = &$GLOBALS['turba_shares']->listShares(
                $user, Horde_Perms::EDIT, $user);

            /* Look for the deleted user's default share and remove it */
            foreach ($shares as $share) {
                $params = @unserialize($share->get('params'));
                /* Only attempt to delete the user's default share */
                if (!empty($params['default'])) {
                    $config = Turba::getSourceFromShare($share);
                    $driver = Turba_Driver::singleton($config);
                    $result = $driver->removeUserData($user);
                    if (is_a($result, 'PEAR_Error')) {
                        Horde::logMessage($result, 'ERR');
                        $hasError = true;
                    }
                }
            }

            /* Get a list of all shares this user has perms to and remove the
             * perms. */
            $shares = $GLOBALS['turba_shares']->listShares($user);
            if (is_a($shares, 'PEAR_Error')) {
                Horde::logMessage($shares, 'ERR');
            }
            foreach ($shares as $share) {
                $share->removeUser($user);
            }

        }

        if ($hasError) {
            return PEAR::raiseError(sprintf(_("There was an error removing an address book for %s"), $user));
        }

        return true;
    }

}
