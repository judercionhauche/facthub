<?php
class Paginator {
    private $totalItems;
    private $itemsPerPage;
    private $currentPage;
    private $totalPages;

    public function __construct($totalItems, $itemsPerPage = 20, $currentPage = 1) {
        $this->totalItems = max(0, $totalItems);
        $this->itemsPerPage = max(1, $itemsPerPage);
        $this->currentPage = max(1, (int)$currentPage);
        $this->totalPages = (int)ceil($this->totalItems / $this->itemsPerPage);

        if ($this->currentPage > $this->totalPages && $this->totalPages > 0) {
            $this->currentPage = $this->totalPages;
        }
    }

    public function getOffset() {
        return ($this->currentPage - 1) * $this->itemsPerPage;
    }

    public function getLimit() {
        return $this->itemsPerPage;
    }

    public function getCurrentPage() {
        return $this->currentPage;
    }

    public function getTotalPages() {
        return $this->totalPages;
    }

    public function getTotalItems() {
        return $this->totalItems;
    }

    public function hasNextPage() {
        return $this->currentPage < $this->totalPages;
    }

    public function hasPrevPage() {
        return $this->currentPage > 1;
    }

    public function getPageRange() {
        $start = max(1, $this->currentPage - 2);
        $end = min($this->totalPages, $this->currentPage + 2);
        return range($start, $end);
    }

    public function getSQLLimit() {
        return "LIMIT " . $this->getOffset() . "," . $this->getLimit();
    }
}
?>
