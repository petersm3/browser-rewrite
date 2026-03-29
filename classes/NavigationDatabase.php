<?php
// Navigation helper functions to construct top-level dropdown faceted navigation

class NavigationDatabase {
    protected $dbh;

    function __construct($dbh) {
        $this->dbh = $dbh;
    }

    // Obtain all categories sorted by priority
    public function getCategories() {
        try {
            // categories have a `priority` order assigned in the schema for nav display
            $sql = "SELECT category, MIN(priority) as priority FROM categories GROUP BY category ORDER BY priority";
            $st = $this->dbh->prepare($sql);
            $st->execute();
            return $st->fetchAll();
        } catch (PDOException $e) {
            error_log("NavigationDatabase::getCategories() failed: " . $e->getMessage());
            return array();
        }
    }

    // Obtain all filters (sub-categories) per single category; unsorted
    public function getSubCategories($category) {
        try {
            $sql = "SELECT subcategory FROM categories WHERE category = ?";
            if (defined('SORT_SUBCATEGORIES') && SORT_SUBCATEGORIES) {
                $sql .= " ORDER BY subcategory";
            }
            $st = $this->dbh->prepare($sql);
            $values = array($category);
            $st->execute($values);
            return $st->fetchAll();
        } catch (PDOException $e) {
            error_log("NavigationDatabase::getSubCategories() failed: " . $e->getMessage());
            return array();
        }
    }
}
/* vim:set noexpandtab tabstop=4 sw=4: */
?>
