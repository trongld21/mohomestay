ALTER TABLE retail_products MODIFY COLUMN stock_quantity INT NOT NULL DEFAULT 0;
ALTER TABLE retail_stock_movements MODIFY COLUMN stock_after INT NOT NULL;
