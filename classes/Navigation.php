<?php
require_once(APP_PATH . 'classes/NavigationDatabase.php');
require_once(APP_PATH . 'classes/DisplayDatabase.php');

// Create Faceted Navigation header
// Intercepts previously checked items and repopulates check boxes
class Navigation {
    protected $navigationDatabase;
    protected $displayDatabase;

    public function getMenus($get, $dbh = null, $about = 0) {

        $this->navigationDatabase = new NavigationDatabase($dbh);
        $this->displayDatabase = new DisplayDatabase($dbh);

        $colon='%3A'; // urlencode(':')
        $menus='';

$menus.= <<<'EOD'
<nav class="navbar navbar-default">
<div class="container-fluid">
<!-- Brand and toggle get grouped for better mobile display -->
<div class="navbar-header">
<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1" aria-expanded="false">
<span class="sr-only">Toggle navigation</span>
<span class="icon-bar"></span>
<span class="icon-bar"></span>
<span class="icon-bar"></span>
</button>
<a class="navbar-brand" href="/">Browser <span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></a>
EOD;

// If on the Display page provide a back button
if ($about === 2) {
    $backUrl = '/';
    if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
        $backUrl = htmlspecialchars($_SERVER['HTTP_REFERER'], ENT_QUOTES, 'UTF-8');
    }
    $menus.='<a class="navbar-brand" href="' . $backUrl . '">';
    $menus.='Back <span class="glyphicon glyphicon-menu-left" aria-hidden="true"></span></a>';
}

$menus.='</div><div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">';
$menus.='<form method="post" action="/filter" class="navbar-form navbar-left">';
$menus.='<ul class="nav navbar-nav">';

        if(!$about) {
            // Obtain a listing of all (unique) top-level categories
            $categories = $this->navigationDatabase->getCategories();
            // Itterate through each pre-defined category in order and construct drop-down
            foreach ($categories as $category) {
                $categoryRaw = $category['category'];
                $categoryUnderscore = str_replace(' ', '_', $categoryRaw); // Avoid spaces in GET
                $menus.='<li class="dropdown">';
                $menus.='<a href="#" class="dropdown-toggle" data-toggle="dropdown" ';
                $menus.=' role="button" aria-haspopup="true" aria-expanded="false">';
                $menus.=$category['category'];
                $menus.='<span class="caret"></span></a><ul class="dropdown-menu">';
                // Get each individual subcategories per the current category
                $subCategories = $this->navigationDatabase->getSubCategories($categoryRaw);
                foreach ($subCategories as $subCategory) {
                    $subCategoryRaw = $subCategory['subcategory'];
                    $subCategoryUnderscore = str_replace(' ', '_', $subCategoryRaw); // Avoid spaces
                    $subCategoryEncode = urlencode($subCategoryUnderscore); // encode &
                    $checked='';
                    if (isset($get['filter'])) {
                        // If the filter was previouly checked as shown by the current
                        // GET string then re-check it on the current display
                        foreach ($get['filter'] as $getFilter) {
                            $getFilterEncode = urlencode($getFilter); // Encode filter from GET to match below
                            if ("$getFilterEncode" == $categoryUnderscore . $colon . $subCategoryEncode) {
                                $checked='checked';
                            }
                        }
                    }
                    // Previously checked item determined from GET string above
                    $menus.='<li>&nbsp;<input type="checkbox" ' . $checked;
                    // id necessary to match label tag below
                    $menus.=' name="filters[]" id="' . $categoryUnderscore . $colon . $subCategoryEncode;
                    // value for filters[] is represented as category:filter
                    $menus.='" value="' . $categoryUnderscore . $colon . $subCategoryEncode;
                    // Resubmit and update screen on every new check/un-check
                    $menus.='" onchange="this.form.submit();"> ';
                    // Enable text to be clickable along with checkbox
                    // Override bootstrap's default bold style of labels
                    $menus.='<label style="font-weight:normal !important;" for="';
                    $menus.=$categoryUnderscore . $colon . $subCategoryEncode . '">';
                    $menus.=$subCategoryRaw;

                    // Given current search filters applied project what adding this additional
                    // filter would yield in total, projected results.
                    if($checked != 'checked') {
                        $categoryIds = array();
                        if(isset($get['filter'])) {
                            foreach ($get['filter'] as $getFilter) {
                                // Filter is specified as category:subcategory
                                $categorySubcategory = explode(":", $getFilter);
                                $category = str_replace('_' ,' ', $categorySubcategory[0]);
                                $subcategory = urldecode(str_replace('_' ,' ', $categorySubcategory[1]));
                                $categoryId = $this->displayDatabase->getCategoriesId($category, $subcategory);
                                array_push($categoryIds, $categoryId['id']);
                            }
                        }
                        // Add curent unselected filter to array to generate possible set of return accessions
                        $categoryId = $this->displayDatabase->getCategoriesId($categoryRaw, $subCategoryRaw);
                        array_push($categoryIds, $categoryId['id']);
                        $filterMatches = $this->displayDatabase->getFilterMatches($categoryIds);
                        $menus.= '&nbsp;&nbsp;<span class="badge">' . count($filterMatches);
                    } else {
                        $menus.= '&nbsp;&nbsp;<span class="badge">0';
                    }
                    $menus.='</span></label></li>';
                }
                $menus.='</ul></li>';
            }
            // Results per page dropdown
            $currentLimit = 10;
            if(isset($get['limit']) && intval($get['limit']) > 0) {
                $currentLimit = intval($get['limit']);
            }
            $limitOptions = array(10, 50, 100, 250, 500);
            $menus.='<li class="dropdown">';
            $menus.='<a href="#" class="dropdown-toggle" data-toggle="dropdown"';
            $menus.=' role="button" aria-haspopup="true" aria-expanded="false">';
            $menus.='Per page: ' . $currentLimit;
            $menus.='<span class="caret"></span></a>';
            $menus.='<ul class="dropdown-menu">';
            foreach($limitOptions as $opt) {
                $menus.='<li>&nbsp;<input type="radio" name="limit" id="limit_' . $opt;
                $menus.='" value="' . $opt . '"';
                if($opt === $currentLimit) {
                    $menus.=' checked';
                }
                $menus.=' onchange="this.form.submit();"> ';
                $menus.='<label style="font-weight:normal !important;" for="limit_' . $opt . '">';
                $menus.=$opt . '</label></li>';
            }
            $menus.='</ul></li>';

            $menus.='<li>';
            // Submit button for WCAG as screen reader may not implement JS onchange
            $menus.='<button type="submit" class="btn btn-link">Submit</button></li>';
        }

// Close drop down menus and list About menu to right
$menus.= <<<'EOD'
</ul>
</form>
<ul class="nav navbar-nav navbar-right">
<li><a href="/about">About</a></li>
</ul>
</div><!-- /.navbar-collapse -->
</div><!-- /.container-fluid -->
</nav>
<div class="container">
EOD;
        // Display currently applied filters on main page (not About or singe accession Display page)
        if(!$about) {
            $error=0;
            // Validate that GET entires match values in `categories` table
            if(isset($get['filter'])) {
                foreach ($get['filter'] as $getFilter) {
                    // Filter is specified as category:subcategory
                    $categorySubcategory = explode(":", $getFilter);
                    $category = str_replace('_', ' ', $categorySubcategory[0]);
                    $subcategory = urldecode(str_replace('_' ,' ', $categorySubcategory[1]));
                    $categoryId = $this->displayDatabase->getCategoriesId($category, $subcategory);
                    if(($error === 0) && ($categoryId['id'] < 1)) {
                        $error=1;
                        $menus.='<div class="alert alert-danger" role="alert">';
                        $menus.='<span class="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span>';
                        $menus.='<span class="sr-only">Error:</span>';
                        $menus.=' Filter: "' . htmlspecialchars($getFilter, ENT_QUOTES, 'UTF-8') . '" is not valid; please check your URL.';
                        $menus.='</div>';
                    }
                }
            }

            if($error === 0) {
                $menus.='<ol class="breadcrumb">';
                if(isset($get['filter'])) {
                    foreach ($get['filter'] as $filter) {
                        $menus.='<li>' . htmlspecialchars(str_replace('_', ' ', $filter), ENT_QUOTES, 'UTF-8') . '</li> ';
                    }
                    $menus.='<li><a href="/">Clear all filters</a></li>';
                } else {
                    $menus.= '<li>Filters: <i>none</i></li>';
                }
                $menus.='</ol>';

                // If no filters show a default message
                if(!isset($get['filter'])) {
                    $menus.='<div class="jumbotron">';
                    $menus.='Select filters from the dropdown categories above to begin your search.';
                    $menus.='</div>';
                }
            }
        }
        $menus.='</div>'; // Close container
        return $menus;
    }
/* vim:set noexpandtab tabstop=4 sw=4: */
}
