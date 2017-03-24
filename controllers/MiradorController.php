<?php

/**
 * Controller for Mirador viewers.
 */
class IiifItems_MiradorController extends IiifItems_BaseController {
    protected static $allowedThings = array('Collection', 'Item', 'File');
    
    /**
     * Renders a Mirador viewer for the given collection, item or file.
     * GET :things/mirador/:id
     * 
     * @throws Omeka_Controller_Exception_404
     */
    public function viewerAction() {
        $type = $this->getParam('things');
        $class = Inflector::titleize(Inflector::singularize($type));
        if (!in_array($class, self::$allowedThings)) {
            throw new Omeka_Controller_Exception_404;
        }
        $this->__passModelToView();
    }
    
    /**
     * Renders a Mirador viewer featuring multiple items and/or manifests.
     * Pass GET parameter items=(comma separated list of item IDs) to embed items.
     * Pass GET parameter u[]=(URL) to embed manifests
     * 
     * GET iiif-items/multiviewer
     */
    public function multiviewerAction() {
        if ($this->getParam('items')) {
            $this->view->item_ids = explode(',', $this->getParam('items'));
        }
        $this->view->manifests = $this->getParam('u');
    }
    
    /**
     * Renders an annotation-enabled Mirador viewer for the given manifest-type collection or non-annotation item.
     * GET: :things/:id/annotator
     * 
     * @throws Omeka_Controller_Exception_404
     */
    public function annotatorAction() {
        // Check existence
        $id = $this->getParam('id');
        $type = $this->getParam('things');
        $class = Inflector::titleize(Inflector::singularize($type));
        $thing = get_record_by_id($class, $id);
        if (empty($thing) || !in_array($class, self::$allowedThings)) {
            throw new Omeka_Controller_Exception_404;
        }
        // Collection
        if ($class == 'Collection') {
            // Reject Collection-type items
            if (raw_iiif_metadata($thing, 'iiifitems_collection_type_element') == 'Collection') {
                throw new Omeka_Controller_Exception_404;
            }
            $this->__passModelToView();
            return;
        }
        // Item
        elseif ($class == 'Item') {
            // Reject Annotation-type items
            if ($thing->item_type_id == get_option('iiifitems_annotation_item_type')) {
                throw new Omeka_Controller_Exception_404;
            }
            $this->__passModelToView();
            return;
        }
        // Reject all others
        $this->__respondWithJson(null, 400);
    }
}
?>
