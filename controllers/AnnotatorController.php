<?php

/**
 * Controller for endpoints used by the annotator
 * @package controllers
 */
class IiifItems_AnnotatorController extends IiifItems_BaseController {
    
    /**
     * Renders a JSON array of annotations filed under the given manifest-type collection or non-annotation item.
     * A "uri" GET parameter must be passed to indicate the canvas ID to list annotations from.
     * GET iiif-items/annotator/:things/:id/index?uri=...
     * 
     * [{ANNOTATION}, {ANNOTATION}, ...]
     */
    public function indexAction() {
        // Sanity checks
        $this->__blockPublic();
        $this->__restrictVerb('GET');
        if (empty($this->getParam('uri'))) {
            $this->__respondWithJson(null, 400);
            return;
        }
        $contextRecordType = $this->getParam('things');
        $contextRecordId = $this->getParam('id');
        if (!($contextThing = $this->__getThing($contextRecordType, $contextRecordId))) {
            $this->__respondWithJson(null, 400);
            return;
        }
        $uri = $this->getParam('uri');
        if (!($thing = IiifItems_Util_Annotation::findAttachmentInContextByUri($contextThing, $uri))) {
            $this->__respondWithJson(null, 400);
            return;
        }
        
        // Pull all annotations that belong to $thing
        // Include access permissions
        $json = IiifItems_Util_Annotation::findAnnotationsFor($thing, true);
        
        // Respond [<anno1>...<annon>]
        $this->__respondWithJson($json);
        return;
    }
    
    /**
     * Processes a POSTed annotation from Mirador and responds with the annotation added to the given manifest-type collection or non-annotation item.
     * POST iiif-items/annotator/:things/:id
     * 
     * {CREATED ANNOTATION}
     */
    public function createAction() {
        // Sanity check
        $this->__blockPublic();
        $this->__restrictVerb('POST');
        $contextRecordType = $this->getParam('things');
        $contextRecordId = $this->getParam('id');
        if (!($contextThing = $this->__getThing($contextRecordType, $contextRecordId))) {
            $this->__respondWithJson(null, 400);
            return;
        }
        //Decode request params from JSON
        $paramStr = file_get_contents('php://input');
        $params = json_decode($paramStr, true);
        unset($params['@id']);
        // Extract on canvas
        $on = $this->__extractOn($params);
        // Extract selector
        $selector = $this->__extractSvg($params);
        // Extract preview dimensions (xywh tuple)
        $previewDimensions = $this->__extractXywh($params);
        // Extract main text and tags
        $body = "";
        $tags = array();
        foreach ($params['resource'] as $resource) {
            switch ($resource['@type']) {
                case 'dctypes:Text': $body = $resource['chars']; break;
                case 'oa:Tag': $tags[] = $resource['chars']; break;
            }
        }
        // Strip proprietary _dims attribute from rigged endpoint
        // This comes from Mirador 2.2 and below
        if (isset($params['_dims'])) {
            unset($params['_dims']);
        }
        // Read and strip proprietary _iiifitems_access attribute
        if (isset($params['_iiifitems_access']) && in_array(current_user()->role, array('super', 'admin'))) {
            $isPublic = !!$params['_iiifitems_access']['public'];
            $isFeatured = !!$params['_iiifitems_access']['featured'];
        } else {
            $isPublic = false;
            $isFeatured = false;
        }
        unset($params['_iiifitems_access']);
        // Trace back to the target Item and remember its UUID
        $originalItem = IiifItems_Util_Annotation::findAttachmentInContextByUri($contextThing, $on);
        if (!$originalItem) {
            $this->__respondWithJson(null, 400);
            return;
        }
        $uuid = raw_iiif_metadata($originalItem, 'iiifitems_item_uuid_element');
        if (!$uuid) {
            $uuid = generate_uuid();
            $originalItem->addElementTextsByArray(array(
                'IIIF Item Metadata' => array(
                    'UUID' => array(array('text' => $uuid, 'html' => false)),
                ),
            ));
            $originalItem->save();
        }
        // Save
        $newItem = insert_item(array(
            'public' => $isPublic,
            'featured' => $isFeatured,
            'item_type_id' => get_option('iiifitems_annotation_item_type'),
            'tags' => join(',', $tags),
        ), array(
            'Dublin Core' => array(
                'Title' => array(array('text' => 'Annotation: "' . html_entity_decode(snippet_by_word_count($body)) . '"', 'html' => false)),
            ),
            'Item Type Metadata' => array(
                'On Canvas' => array(array('text' => $uuid, 'html' => false)),
                'Selector' => array(array('text' => $selector, 'html' => false)),
                'Annotated Region' => array(array('text' => join(',', $previewDimensions), 'html' => false)),
                'Text' => array(array('text' => $body, 'html' => true)),
            ),
        ));
        $newItemId = $newItem->id;
        // Add @id and re-save, then respond with @id attached
        $params['@id'] = public_full_url(array('things' => 'items', 'id' => $newItemId, 'typeext' => 'anno.json'), 'iiifitems_oa_uri');
        // Attach new original ID
        get_db()->insert('ElementText', array(
            'record_id' => $newItemId,
            'record_type' => 'Item',
            'html' => 0,
            'element_id' => get_option('iiifitems_item_atid_element'),
            'text' => $params['@id'],
        ));
        // Attach new JSON Data
        get_db()->insert('ElementText', array(
            'record_id' => $newItemId,
            'record_type' => 'Item',
            'html' => 0,
            'element_id' => get_option('iiifitems_item_json_element'),
            'text' => json_encode($params, JSON_UNESCAPED_SLASHES),
        ));
        // Attach preview image based on first image
        if (isset($previewDimensions) && !IiifItems_Util_Canvas::isNonIiifItem($originalItem)) {
            Zend_Registry::get('bootstrap')->getResource('jobs')->sendLongRunning('IiifItems_Job_AddAnnotationThumbnail', array(
                'originalItemId' => $originalItem->id,
                'annotationItemId' => $newItem->id,
                'dims' => $previewDimensions,
            ));
        }
        // Tack back the proprietary _iiifitems_access attribute
        $params['_iiifitems_access'] = array(
            'public' => $newItem->public,
            'featured' => $newItem->featured,
            'owner' => $newItem->owner_id,
        );
        $this->__respondWithJson($params);
    }
    
    /**
     * Deletes the submitted annotation from Mirador as filed under the given manifest-type collection or non-annotation item.
     * Responds "OK" if successful
     * DELETE iiif-items/annotator/:things/:id/delete
     * 
     * OK
     * 
     * @throws Omeka_Controller_Exception_404
     */
    public function deleteAction() {
        // Sanity check
        $this->__blockPublic();
        $this->__restrictVerb('DELETE');
        $contextRecordType = $this->getParam('things');
        $contextRecordId = $this->getParam('id');
        if (!($contextThing = $this->__getThing($contextRecordType, $contextRecordId))) {
            $this->__respondWithJson(null, 400);
            return;
        }
        // Decode JSON
        $paramStr = file_get_contents('php://input');
        $params = json_decode($paramStr, true);
        $id = $params['id'];
        
        // Find the annotation by that ID and delete it
        if ($annoTexts = get_db()->getTable('ElementText')->findBySql('element_texts.element_id = ? AND element_texts.text = ?', array(get_option('iiifitems_item_atid_element'), $id))) {
            if ($annoItem = get_record_by_id('Item', $annoTexts[0]->record_id)) {
                // Check permissions
                $user = current_user();
                switch ($user->role) {
                    case 'contributor':
                        if ($user->id != $annoItem->owner_id) {
                            $this->__respondWithJson(null, 403);
                            return;
                        }
                    break;
                    case 'researcher':
                        $this->__respondWithJson(null, 403);
                        return;
                }
                // Delete the annotation
                $annoItem->delete();
                $this->__respondWithJson(array("status" => "OK"));
                return;
            }
        }
        throw new Omeka_Controller_Exception_404;
    }
    
    /**
     * Deletes the submitted annotation from Mirador as filed under the given manifest-type collection or non-annotation item.
     * Responds with the updated annotation if successful.
     * PUT iiif-items/annotator/:things/:id/update
     * 
     * {UPDATED ANNOTATION}
     * 
     * @throws Omeka_Controller_Exception_404
     */
    public function updateAction() {
        // Sanity check
        $this->__blockPublic();
        $this->__restrictVerb('PUT');
        $contextRecordType = $this->getParam('things');
        $contextRecordId = $this->getParam('id');
        if (!($contextThing = $this->__getThing($contextRecordType, $contextRecordId))) {
            $this->__respondWithJson(null, 400);
            return;
        }
        // Decode JSON
        $jsonStr = file_get_contents('php://input');
        $json = json_decode($jsonStr, true);
        $atid = $json['@id'];
        // Extract on canvas
        $on = $this->__extractOn($json);
        // Extract selector
        $selector = $this->__extractSvg($json);
        // Extract main text and tags
        $text = '';
        $textIsHtml = false;
        $tags = array();
        foreach ($json['resource'] as $resource) {
            switch ($resource['@type']) {
                case 'oa:Tag':
                    $tags[] = $resource['chars'];
                break;
                case 'dctypes:Text': case 'cnt:ContentAsText':
                    $text = $resource['chars'];
                    $textIsHtml = $resource['format'] == 'text/html';
                break;
            }
        }
        // Save
        if ($annoTexts = get_db()->getTable('ElementText')->findBySql('element_texts.element_id = ? AND element_texts.text = ?', array(get_option('iiifitems_item_atid_element'), $atid))) {
            if ($annoItem = get_record_by_id('Item', $annoTexts[0]->record_id)) {
                // Check permissions
                $user = current_user();
                switch ($user->role) {
                    case 'super': case 'admin':
                        $isPublic = !!$json['_iiifitems_access']['public'];
                        $isFeatured = !!$json['_iiifitems_access']['featured'];
                    break;
                    case 'contributor':
                        $isPublic = $annoItem->public;
                        $isFeatured = $annoItem->featured;
                        if ($user->id != $annoItem->owner_id) {
                            $this->__respondWithJson(null, 403);
                            return;
                        }
                    break;
                    case 'researcher':
                        $this->__respondWithJson(null, 403);
                        return;
                }
                unset($json['_iiifitems_access']);
                // Apply changes
                $annoItem->applyTagString(join(',', $tags));
                $newTextsArray = array(
                    'Item Type Metadata' => array(
                        'Text' => array(array('text' => $text, 'html' => true)),
                    ),
                    'IIIF Item Metadata' => array(
                        'JSON Data' => array(array('text' => $this->__json_encode($json), 'html' => false)),
                    ),
                );
                $annoItem->deleteElementTextsByElementId(array(
                    get_option('iiifitems_annotation_text_element'),
                    get_option('iiifitems_item_json_element'),
                ));
                $annoItem->addElementTextsByArray($newTextsArray);
                $annoItem->public = $isPublic;
                $annoItem->featured = $isFeatured;
                $annoItem->save();
                $this->__insertIiifItemsAccess($json, $annoItem);
                $this->__respondWithJson($json);
                return;
            }
        }
        // Respond with changes
        throw new Omeka_Controller_Exception_404;
    }
    
    /**
     * The annotator wrapper page for the given manifest-type collection or non-annotation item.
     * GET :things/:id/annotate
     */
    public function annotateAction() {
        $this->__passModelToView();
    }
    
    /**
     * Quick helper for retrieving a record by name and ID
     * @param string $type The type of record to retrieve
     * @param integer $id The ID to retrieve
     * @return Record
     */
    private function __getThing($type, $id) {
        $class = Inflector::titleize(Inflector::singularize($type));
        return get_record_by_id($class, $id);
    }
    
    /**
     * Return the canvas that the annotation is attached on.
     * @param array $params OA annotation JSON array data
     * @return string
     */
    private function __extractOn($params) {
        if (isset($params['on']['full'])) {
            return $params['on']['full'];
        }
        if (isset($params['on'][0]['full'])) {
            return $params['on'][0]['full'];
        }
        return null;
    }
    
    /**
     * Return the xywh selector of the annotation.
     * @param array $params OA annotation JSON array data
     * @return array 4-entry array of x, y, width, height
     */
    private function __extractXywh($params) {
        if (isset($params['_dims'])) {
            return $params['_dims'];
        }
        if (isset($params['on'][0]['selector']['default']['value'])) {
            return explode(',', substr($params['on'][0]['selector']['default']['value'], 5));
        } 
        return null;
    }
    
    /**
     * Return the SVG selector of the annotation.
     * @param array $params OA annotation JSON array data
     * @return string
     */
    private function __extractSvg($params) {
        if (isset($params['on']['selector']['value'])) {
            return $params['on']['selector']['value'];
        }
        if (isset($params['on'][0]['selector']['item']['value'])) {
            return $params['on'][0]['selector']['item']['value'];
        } 
        return null;
    }
    
    /**
     * Inserts the proprietary _iiifitems_access property into the given JSON data.
     * @param array $json The JSON array data
     * @param Item $annoItem The annotation-type item that this is based on
     */
    private function __insertIiifItemsAccess(&$json, $annoItem) {
        // Tack back the proprietary _iiifitems_access attribute
        $json['_iiifitems_access'] = array(
            'public' => $annoItem->public,
            'featured' => $annoItem->featured,
            'owner' => $annoItem->owner_id,
        );
    }
}
