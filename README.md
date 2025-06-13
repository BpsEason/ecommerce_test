Sure, here's a README for your project:

# E-commerce Order Management System

This repository contains the database setup and a simple PHP API for an e-commerce order management system.

## Features

* **User Management**: Basic user table.
* **Product Management**: Products with price, stock, and soft delete functionality.
* **Order Management**: Create, view, and update orders.
* **Order Items**: Link products to orders with quantities and subtotals.
* **Stock Management**: Automatic stock deduction upon order creation.
* **API Endpoints**: RESTful API for orders and products.
* **Database Transactions**: Ensures data integrity during order creation.

## Technologies Used

* **Database**: MySQL
* **Backend**: PHP (with PDO for database interaction)

## Setup Instructions

### 1. Database Setup

1.  **Create the database and tables**:
    Import the `database_setup.sql` file into your MySQL database. This will create a database named `ecommerce_test` and all the necessary tables.

    ```bash
    mysql -u your_username -p < database_setup.sql
    ```
    (Replace `your_username` with your MySQL username. You will be prompted for your password.)

### 2. API Setup

1.  **Place the API file**:
    Place the `ecommerce_api.php` file in your web server's document root (e.g., `htdocs` for Apache, `www` for Nginx).

2.  **Configure Database Connection**:
    Open `ecommerce_api.php` and update the database connection details if they are different from the defaults:
    ```php
    $host = 'localhost';
    $dbname = 'ecommerce_test';
    $username = 'root';
    $password = ''; // Change this to your MySQL password
    ```
    For production environments, it is highly recommended to use environment variables for sensitive information like database credentials.

3.  **Enable URL Rewriting (if needed)**:
    For clean URLs (e.g., `api/orders` instead of `ecommerce_api.php/api/orders`), you might need to configure URL rewriting on your web server.

    * **Apache (`.htaccess`)**:
        Create a `.htaccess` file in the same directory as `ecommerce_api.php` with the following content:
        ```apache
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)$ ecommerce_api.php/$1 [L]
        ```

    * **Nginx**:
        Add a `location` block to your Nginx server configuration:
        ```nginx
        location / {
            try_files $uri $uri/ /ecommerce_api.php$is_args$args;
        }
        ```

## API Endpoints

All API endpoints are prefixed with `/api`.

### Orders

* **GET `/api/orders`**
    * **Description**: Get a paginated list of all orders.
    * **Query Parameters**:
        * `page` (optional): Page number (default: 1)
    * **Response**:
        ```json
        {
            "data": [
                {
                    "id": 1,
                    "user_id": 101,
                    "number": "ORD20231026123456789",
                    "status": "pending",
                    "total_amount": "150.00",
                    "created_at": "2023-10-26 10:00:00"
                }
            ],
            "page": 1,
            "total_pages": 5,
            "total_items": 100
        }
        ```

* **GET `/api/orders/{id}`**
    * **Description**: Get details of a specific order by ID.
    * **Response**:
        ```json
        {
            "id": 1,
            "user_id": 101,
            "number": "ORD20231026123456789",
            "status": "pending",
            "total_amount": "150.00",
            "created_at": "2023-10-26 10:00:00",
            "updated_at": "2023-10-26 10:00:00"
        }
        ```

* **POST `/api/orders`**
    * **Description**: Create a new order. Automatically deducts stock and calculates total amount.
    * **Request Body** (JSON):
        ```json
        {
            "user_id": 1,
            "items": [
                {"product_id": 1, "quantity": 2},
                {"product_id": 2, "quantity": 1}
            ]
        }
        ```
    * **Response**:
        ```json
        {
            "order_id": 1,
            "order_number": "ORD20231026123456789"
        }
        ```
    * **Error Response (Example)**:
        ```json
        {
            "error": "庫存不足",
            "product_id": 1
        }
        ```

* **PUT `/api/orders/{id}`**
    * **Description**: Update the status of an order.
    * **Request Body** (JSON):
        ```json
        {
            "status": "shipped"
        }
        ```
    * **Possible Status Values**: `pending`, `processing`, `shipped`, `delivered`, `cancelled`
    * **Response**:
        ```json
        {
            "success": true
        }
        ```

* **GET `/api/orders/stats`**
    * **Description**: Get order statistics (total orders, total amount, today's orders, today's amount).
    * **Response**:
        ```json
        {
            "total_orders": 123,
            "total_amount": 12345.67,
            "today_orders": 5,
            "today_amount": 543.21
        }
        ```

### Products

* **GET `/api/products`**
    * **Description**: Get a paginated list of available products (where `is_deleted` is `FALSE`).
    * **Query Parameters**:
        * `page` (optional): Page number (default: 1)
    * **Response**:
        ```json
        {
            "data": [
                {
                    "id": 1,
                    "name": "Product A",
                    "price": "29.99",
                    "stock": 100
                }
            ],
            "page": 1,
            "total_pages": 3,
            "total_items": 60
        }
        ```

## Error Handling

* API responses will return a JSON object with an `error` key in case of an issue.
* HTTP status codes are used to indicate the type of error (e.g., 400 for bad request, 404 for not found, 500 for server error).
* Errors are logged to `error.log` in the same directory as `ecommerce_api.php` if `APP_ENV` is set to `development`.

## Future Enhancements

* **User Authentication and Authorization**: Implement a robust authentication system (e.g., JWT) to secure API endpoints.
* **More comprehensive product management**: Add endpoints for creating, updating, and deleting products.
* **User-specific order history**: Filter orders by authenticated user.
* **Payment Gateway Integration**: Integrate with payment providers.
* **Logging**: Enhance logging mechanisms for better debugging and monitoring.
* **Environment Variables**: Fully utilize environment variables for all sensitive configurations.
* **API Versioning**: Implement API versioning (e.g., `/api/v1/orders`).
* **Input Validation**: More robust server-side input validation beyond basic type checks.
* **Unit and Integration Tests**: Add automated tests for the API and database interactions.
