<?php
$this->setLayoutVar('pageTitle', 'Display ' . $accession);
?>
<a href="#content" class="sr-only">Skip navigation</a>
<?php echo $navigation ?>
<div class="container">
<main id="content">
<h1 class="sr-only">Accession Detail</h1>
<?php echo $result ?>
</main>
</div>
