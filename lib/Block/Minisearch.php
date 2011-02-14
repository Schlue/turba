<?php
/**
 * Allow searching of address books from the portal.
 */
class Turba_Block_Minisearch extends Horde_Block
{
    /**
     */
    public function getName()
    {
        return _("Contact Search");
    }

    /**
     */
    protected function _title()
    {
        return Horde::url($GLOBALS['registry']->getInitialPage(), true)->link()
            . $this->getName() . '</a>';
    }

    /**
     */
    protected function _content()
    {
        if ($GLOBALS['browser']->hasFeature('iframes')) {
            Horde::addScriptFile('prototype.js', 'horde');
            Horde::startBuffer();
            include TURBA_TEMPLATES . '/block/minisearch.inc';
            return Horde::endBuffer();
        }

        return '<em>' . _("A browser that supports iframes is required") . '</em>';
    }

}