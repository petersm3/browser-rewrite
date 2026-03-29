<?php
require_once(APP_PATH . 'config/config.php');
require_once(APP_PATH . 'classes/Database.php');
require_once(APP_PATH . 'classes/Navigation.php');
require_once(APP_PATH . 'classes/Display.php');

// Dummy controller for About page; takes no arguments
class DisplayController extends AppController {

    private $database;
    private $dbh;
    private $navigation;
    private $display;

    protected function beforeAction() {
        // parent::beforeAction(); // chain to parent
        $this->database = new Database;
        $this->dbh = $this->database->getConnection(); // Get database handle
    }

    public function actionIndex() {
        $this->navigation = new Navigation;
        $this->display = new Display;
        // Set Navigation to display for 'Display' page by specifying second arg as 2
        // No database handle (dbh) to pass for Display page; use null
        $this->setVar('navigation', $this->navigation->getMenus($this->get, null, 2));
        if(isset($this->get['id']) && (intval($this->get['id']) > 0)) {
            $this->setVar('accession', intval($this->get['id']));
            $this->setVar('result', $this->display->getAccession(intval($this->get['id']), $this->dbh));
        } else {
            $this->setVar('accession', '');
            $this->setVar('result', 'No accession provided.');
        }
    }
}
/* vim:set noexpandtab tabstop=4 sw=4: */
?>
