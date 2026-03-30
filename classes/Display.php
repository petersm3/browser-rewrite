<?php
require_once(APP_PATH . 'classes/DisplayDatabase.php');
// Display functions for both the main page when navigating and for the single accesion display page

class Display {
    protected $displayDatabase;

    // Obtain all results given the currently checked filter(s)
    public function getResults($get, $dbh) {
        $this->displayDatabase = new DisplayDatabase($dbh);

        $offset=0;
        $limit=10; // Default results per page
        if(isset($get['limit']) && intval($get['limit']) > 0 && intval($get['limit']) <= 500) {
            $limit = intval($get['limit']);
        }

        $results='';
        if(isset($get['filter'])) {
            $results='<div class="container">';
            $categoryIds = array();
            // Lookup categories primary key `id` for each filer selected by user
            foreach ($get['filter'] as $getFilter) {
                // Filter is specified as category:subcategory
                $categorySubcategory = explode(":", $getFilter);
                $category = str_replace('_', ' ', $categorySubcategory[0]);
                $subcategory = urldecode(str_replace('_' ,' ', $categorySubcategory[1]));
                $categoryId = $this->displayDatabase->getCategoriesId($category, $subcategory);
                // If an invalid filter is supplied via GET
                if($categoryId['id'] < 1) {
                    return;
                } 
                array_push($categoryIds, $categoryId['id']);
            }

            if(isset($get['offset'])) {
                $offset = intval($get['offset']);
            }

            // Get all accessions that match filter criteria
            $filterMatches = $this->displayDatabase->getFilterMatches($categoryIds, $limit, $offset);
            if(count($filterMatches) === 0) {
                $results.='<div class="jumbotron">';
                $results.='No matches found satisfying an exact match to the above filter critera.';
                $results.='</div>'; 
            } else {
                // Display all matches on front page
                foreach ($filterMatches as $filterMatch) {
                    $properties = $this->displayDatabase->getProperties($filterMatch['fk_properties_id']);
                    $results.='<div class="jumbotron">';
                    $results.='<div class="row">';
                    $results.='<div class="col-sm-5">';
                    $results.='<a href="/display?id=' . $filterMatch['fk_properties_id'] . '"';
                    $results.=' aria-label="View accession ' . intval($filterMatch['fk_properties_id']) . '">';
                    $results.='<img class="img-responsive" src="/img
                    $results.='/320x240/000/fff.png?text=%20';
                    $results.=htmlspecialchars($properties['image'], ENT_QUOTES, 'UTF-8');
                    $results.='" alt="' . htmlspecialchars($properties['image'], ENT_QUOTES, 'UTF-8') . '"/></a>';
                    $results.='</div>';
                    $results.='<div class="col-sm-2"></div>';
                    $results.='<div class="col-sm-5">';
                    $results.='<table class="table">';
                    $results.='<tr><th scope="row">Accession:</th><td>';
                    $results.='<a href="/display?id=' . intval($filterMatch['fk_properties_id']) . '"';
                    $results.=' aria-label="View accession ' . intval($filterMatch['fk_properties_id']) . '">';
                    $results.=intval($filterMatch['fk_properties_id']) . '</a></td></tr>';
                    $results.='<tr><th scope="row">Address:</th><td>' . htmlspecialchars($properties['street_address'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
                    $results.='<tr><th scope="row">Photographer:</th><td>' . htmlspecialchars($properties['photographer'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
                    $results.='<tr><th scope="row">Date:</th><td>' . htmlspecialchars($properties['date'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
                    $results.='</table>';
                    $results.='</div></div>';
                    $results.='</div>';
                }
            }

            // Pagination
            $filterMatchCount=$this->displayDatabase->getFilterMatchCount($categoryIds);
            $totalPages=ceil($filterMatchCount/$limit);
            // Result count display
            $startResult = $offset + 1;
            $endResult = min($offset + $limit, $filterMatchCount);
            $results.='<div class="text-center">';
            $results.='<p aria-live="polite">Showing ' . $startResult . '-' . $endResult . ' of ' . $filterMatchCount . ' results</p>';
            $results.='<nav aria-label="Pagination">';
            $results.='<ul class="pagination">';
            // Construct URL of current page
            $urlFilter='';
            foreach ($get['filter'] as $getFilter) {
                $urlFilter.='filter[]=' .  htmlspecialchars(urlencode($getFilter), ENT_QUOTES, 'UTF-8') . '&amp;';
            }
            if($limit !== 10) {
                $urlFilter.='limit=' . $limit . '&amp;';
            }
            for ($page = 1; $page <= $totalPages; $page++) {
                $currentOffset = (($page-1)*$limit);
                if($page == 1) {
                    $currentOffset = 0;
                }
                if($currentOffset == $offset) {
                    $results.='<li class="active">';
                } else {
                    $results.='<li>';
                }
                $results.='<a href="/?' . $urlFilter;
                $results.='offset=' . $currentOffset . '"';
                if($currentOffset == $offset) {
                    $results.=' aria-current="page"';
                } else {
                    $results.=' aria-label="Go to page ' . $page . '"';
                }
                $results.='>' . $page;
                if($currentOffset == $offset) {
                    $results.=' <span class="sr-only">(current)</span>';
                }
                $results.='</a></li>';
            }
            $results.='</ul>';
            $results.='</nav>';
            $results.='</div>';

            $results.='</div>'; // close container
        }
        return $results; 
    }

    // Obtain properties and attributes for a single accession record and display
    public function getAccession($id, $dbh) {
        $this->displayDatabase = new DisplayDatabase($dbh);
        $properties = $this->displayDatabase->getProperties(intval($id));
        if (intval($properties['id']) !== intval($id)) {
            return "Accession " . intval($id) . ' not found.';
        }
        $attributes = $this->displayDatabase->getAttributes(intval($id));

        $results='';
        $results.='<div class="jumbotron">';
        $results.='<img class="img-responsive" src="/img/640x480/000/fff.png?text=%20';
        $results.=htmlspecialchars($properties['image'], ENT_QUOTES, 'UTF-8');
        $results.='" alt="' . htmlspecialchars($properties['image'], ENT_QUOTES, 'UTF-8') . '"/>';
        $results.='<table class="table">';
        $results.='<tr><th scope="row">Accession:</th><td>';
        $results.=intval($properties['id']). '</td></tr>';
        $results.='<tr><th scope="row">Address:</th><td>' . htmlspecialchars($properties['street_address'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $results.='<tr><th scope="row">Photographer:</th><td>' . htmlspecialchars($properties['photographer'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $results.='<tr><th scope="row">Date:</th><td>' . htmlspecialchars($properties['date'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        foreach ($attributes as $attribute) {
            $results.='<tr><th scope="row">' . htmlspecialchars($attribute['name'], ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($attribute['value'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $results.='</table>';
        $results.='</div>';

        return $results;
    }
/* vim:set noexpandtab tabstop=4 sw=4: */
}
?>
