<?php
// Display functions for both the front page (set of items) and individual display pages (single accession)

class DisplayDatabase {
    protected $dbh;

    function __construct($dbh) {
        $this->dbh = $dbh;
    }

    protected function beforeAction() {
        parent::beforeAction(); // chain to parent
    }

    // Lookup function to get primary key for each individual filter specified
    public function getCategoriesId($category, $subcategory) {
        try {
            $sql="SELECT id FROM categories where category = ? and subcategory = ?";
            $st = $this->dbh->prepare($sql);
            $values = array($category, $subcategory);
            $st->execute($values);
            $result = $st->fetch();
            return $result ? $result : array('id' => 0);
        } catch (PDOException $e) {
            error_log("DisplayDatabase::getCategoriesId() failed: " . $e->getMessage());
            return array('id' => 0);
        }
    }

    // Obtain a list of fk_properties_ids that match the filter criteria exactly
    // The return value is used by Navigation to indiciate potential result sets when adding
    // a new, single filter
    // limit and offset used for pagination
    public function getFilterMatches($categoriesIds, $limit = 0, $offset = 0) {
        try {
            // http://stackoverflow.com/questions/920353/can-i-bind-an-array-to-an-in-condition
            $qMarks = ' ? ';
            if(count($categoriesIds) > 1) {
                $qMarks = str_repeat('?,', count($categoriesIds) - 1) . '?';
            }
            $sql="SELECT fk_properties_id, COUNT(fk_properties_id) as count_fk_properties_id";
            $sql.=" FROM filters WHERE fk_categories_id ";
            $sql.="IN ($qMarks) GROUP BY fk_properties_id HAVING count_fk_properties_id = ";
            $sql.=count($categoriesIds);
            if($limit > 0) {
                $sql.=" LIMIT " . intval($limit);
            }
            if($offset > 0) {
                $sql.=" OFFSET " . intval($offset);
            }
            $st = $this->dbh->prepare($sql);
            $st->execute($categoriesIds);
            return $st->fetchAll();
        } catch (PDOException $e) {
            error_log("DisplayDatabase::getFilterMatches() failed: " . $e->getMessage());
            return array();
        }
    }

    // Obtain all of the properties for a single accession
    // This information is used by each accession on both the main page listing
    // and by the single accession display page
    public function getProperties($propertiesId) {
        try {
            $sql="SELECT id, image, street_address, photographer, date ";
            $sql.=" FROM properties where id = ?";
            $st = $this->dbh->prepare($sql);
            $values = array(intval($propertiesId));
            $st->execute($values);
            $result = $st->fetch();
            return $result ? $result : array('id' => 0, 'image' => '', 'street_address' => '', 'photographer' => '', 'date' => '');
        } catch (PDOException $e) {
            error_log("DisplayDatabase::getProperties() failed: " . $e->getMessage());
            return array('id' => 0, 'image' => '', 'street_address' => '', 'photographer' => '', 'date' => '');
        }
    }

    // Obtain all of the additional metadata ("attributes") for a single
    // accession record, which is shown only on the single accession display page
    // There can be N number of key/value pairs (attributes) per accession ID
    public function getAttributes($propertiesId) {
        try {
            $sql="SELECT name, value ";
            $sql.=" FROM attributes where fk_properties_id = ?";
            $st = $this->dbh->prepare($sql);
            $values = array(intval($propertiesId));
            $st->execute($values);
            return $st->fetchAll();
        } catch (PDOException $e) {
            error_log("DisplayDatabase::getAttributes() failed: " . $e->getMessage());
            return array();
        }
    }
}
/* vim:set noexpandtab tabstop=4 sw=4: */
?>
