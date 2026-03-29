<?php
require_once(APP_PATH . 'classes/Filter.php');

// Takes filter arguments as POST, creates redirect to / with GET arguments
class FilterController extends AppController {
    private $filter;

    public function actionIndex() {
        $this->filter = new Filter;
        // Transform POST arguments to string of GET arguments
        $getArgs=$this->filter->parse($this->post);
        $url = '/?';
        // Redirect back to main page with GET arguments
        $this->redirect($url . $getArgs);
        exit();
    }
}
/* vim:set noexpandtab tabstop=4 sw=4: */
?>
