<?php
require_once(APP_PATH . 'config/config.php');
require_once(APP_PATH . 'classes/Database.php');
require_once(APP_PATH . 'classes/Navigation.php');
require_once(APP_PATH . 'classes/Display.php');

class PageController extends AppController
{
    private $database;
    private $dbh;
    private $navigation;
    private $display;

    protected function beforeAction() {
        // parent::beforeAction(); // chain to parent
        $this->database = new Database;
        $this->dbh = $this->database->getConnection(); // Get database handle
    }

    public function actionView($pageName = 'home')
    {
        $this->navigation = new Navigation;
        $this->display = new Display;
        // Generate faceted navigation
        $this->setVar('navigation', $this->navigation->getMenus($this->get, $this->dbh, 0));
        // Display resuts based upon GET
        $this->setVar('results', $this->display->getResults($this->get, $this->dbh));

        if (strpos($pageName, '../') !== false)
        {
            throw new Lvc_Exception('File Not Found: ' . $sourceFile);
        }

        $this->loadView('page/' . rtrim($pageName, '/'));
    }
}
/* vim:set noexpandtab tabstop=4 sw=4: */
?>
