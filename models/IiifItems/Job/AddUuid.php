<?php

class IiifItems_Job_AddUuid extends Omeka_Job_AbstractJob {
    private $batchSize = 100;
    
    public function perform() {
        try {
            $jobStatus = $this->createJobStatus(
                $this->countRecords('Collection')+$this->countRecords('Item')
            );
            $this->addUuidToType('Collection', $jobStatus);
            $this->addUuidToType('Item', $jobStatus);
            $jobStatus->status = 'Completed';
            $jobStatus->progress = $jobStatus->total;
            $jobStatus->modified = date('Y-m-d H:i:s');
            $jobStatus->save();
        } catch (Exception $e) {
            debug($e->getTraceAsString());
        }
    }
    
    private function countRecords($type) {
        return get_db()->getTable($type)->count();
    }
    
    private function createJobStatus($total=0) {
        $jobStatusId = $this->_db->insert('IiifItems_JobStatus', array(
            'source' => __('Adding UUID to collections and items'),
            'dones' => 0, 
            'skips' => 0,
            'fails' => 0,
            'status' => 'In Progress', 
            'progress' => 0,
            'total' => $total,
            'added' => date('Y-m-d H:i:s'),
        ));
        return $this->_db->getTable('IiifItems_JobStatus')->find($jobStatusId);
    }
    
    private function addUuidToType($type, $jobStatus) {
        // For each batch of 100
        $page = 1;
        $table = get_db()->getTable($type);
        $element = get_db()->getTable('Element')->findByElementSetNameAndElementName('IIIF ' . $type . ' Metadata', 'UUID');
        while ($batch = $table->findBy(array(), $this->batchSize, $page++)) {
            // For each record in the batch
            foreach ($batch as $record) {
                // If its UUID metadata field is empty
                if (!metadata($record, array('IIIF ' . $type . ' Metadata', 'UUID'))) {
                    // Generate a UUID
                    $uuid = generate_uuid();
                    // Set its UUID metadata field to the generated UUID
                    $record->addTextForElement($element, $uuid);
                    // Save it
                    $record->save();
                    // Add one done to job status
                    $jobStatus->dones++;
                    
                } else {
                    $jobStatus->skips++;
                }
                // Update job status
                $jobStatus->progress++;
                $jobStatus->modified = date('Y-m-d H:i:s');
                $jobStatus->save();
            }
        }
    }
}
