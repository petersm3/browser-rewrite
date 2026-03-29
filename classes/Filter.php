<?php
// Take incoming POST values for filter and offset and rewrite as GET arguments

class Filter {
    public function parse($post) {
        $getFilters='';
        if(isset($post['filters'])) {
            foreach ($post['filters'] as $key => $value) {
                $getFilters.='filter[]=' . urlencode($value) . '&';
            }
        }
        if(isset($post['offset'])) {
            $getFilters.='offset=' . intval($post['offset']) . '&';
        }
        // Do not need trailing &
        return substr($getFilters, 0, -1);
    }
/* vim:set noexpandtab tabstop=4 sw=4: */
}
