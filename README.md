# MOSLF Store Backend

RESTful E-Commerce API built with Laravel 12 and PHP 8.2.

This project is a backend system for an e-commerce platform focusing on scalability, security, maintainability, and real-world business logic such as cart synchronization, inventory tracking, and role-based access control.

---

# Tech Stack

- Laravel 12
- PHP 8.2
- MySQL
- Laravel Sanctum (Authentication)
- Spatie Laravel Permission (RBAC)
- PragmaRX Google2FA (Admin 2FA)
- PHPUnit (Testing)

---

# Project Overview

This backend is designed with a pragmatic layered architecture inspired by clean architecture principles, balancing simplicity and scalability.

It avoids unnecessary abstraction in simple CRUD operations while introducing structured layers for complex business logic.

---

# Architecture

HTTP Layer
├── Controllers (HTTP handling only)
├── Form Requests (Validation)
└── API Resources (Response formatting)

Application Layer
├── Services (Core business logic)
├── Actions (Single-responsibility operations)
└── DTOs (Data transfer where needed)

Domain Layer (lightweight)
├── Enums
├── Events
└── Exceptions

Infrastructure Layer
├── Models
├── Repositories (optional usage)
└── Database (Migrations, Seeders)

---

# Design Principles

## Separation of Concerns

- Controllers only handle HTTP requests
- Business logic lives in Services / Actions
- Validation handled via Form Requests
- API responses standardized using Resources

## Pragmatism over Over-Engineering

- Simple CRUD stays simple
- Complex flows (cart, inventory, orders) are structured
- Avoids unnecessary design patterns when not needed

---

# Core Features

## Authentication & Security

- Token-based authentication (Laravel Sanctum)
- Admin Two-Factor Authentication (TOTP)
- Token revocation support
- Rate limiting on auth endpoints
- Role & Permission system (Spatie)
- Protection against IDOR vulnerabilities

## E-Commerce Core

- Product catalog with slug-based routing
- Product variations support
- Wishlist system
- Cart system (guest + authenticated users)
- Automatic cart merging after login
- Order management system

## Inventory System

- Stock tracking per product variation
- Inventory movement history:
    - IN
    - OUT
    - ADJUSTMENT
- Consistent stock updates
- Audit-friendly movement logs

---

# Admin Authentication Flow

Login Request
|
|-- Regular User → Access Token issued immediately
|
|-- Admin User → Temporary Token issued
|
v
Two-Factor Authentication (TOTP)
|
v
Final Access Token Issued

---

# Cart System

The cart system supports both guest and authenticated users.

Key behaviors:

- Guest carts persist until login
- Automatic merging of guest + user cart after login
- Quantity conflict resolution handled automatically
- Inventory-safe updates to prevent over-ordering
- Encapsulated in service layer for clean controller design

---

# Inventory System

Designed for accuracy and traceability:

- Stock tracked per product variant
- Every stock change is logged as a movement record
- Movement types:
    - IN (restock)
    - OUT (sales/order deduction)
    - ADJUSTMENT (manual admin correction)
- Event-driven and observer-based consistency where needed

---

# API Structure

## Public Endpoints

- GET `/api/products`
- GET `/api/products/{product:slug}`
- POST `/api/auth/register`
- POST `/api/auth/login`

## Cart Endpoints

- GET `/api/cart`
- POST `/api/cart`
- PATCH `/api/cart/items/{variantId}`
- DELETE `/api/cart/items/{variantId}`

## User Endpoints

- GET `/api/auth/me`
- GET `/api/user/orders`
- POST `/api/user/orders`
- GET `/api/user/wishlist`
- POST `/api/user/wishlist/{productId}`
- DELETE `/api/user/wishlist/{productId}`

## Admin API (/api/v1)

Protected by:

- Sanctum authentication
- Role-Based Access Control (RBAC)
- Admin middleware

Includes:

- Product CRUD
- Inventory management
- Stock movement tracking

---

# Authentication & Security Notes

- All passwords are hashed using bcrypt
- Sensitive routes are rate-limited
- Admin actions require elevated permissions
- Temporary tokens used for 2FA flow
- Secure environment-based configuration

---

# Testing

- PHPUnit feature tests
- Cart synchronization edge cases
- Inventory concurrency simulations
- Manual end-to-end business flow testing

---

# Development Tools

- Laravel Pint (code style formatting)
- Laravel Pail (logging)
- Laravel Sail (local development environment)
- Faker (test data generation)

---

# Installation

git clone https://github.com/ahmedeied701-crypto/MOSLF-STORE-BACKEND.git
cd MOSLF-STORE-BACKEND
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve

---

# Future Improvements

- Queue-based order processing
- Redis caching layer
- Docker containerization
- CI/CD pipeline
- OpenAPI / Swagger documentation
- Advanced inventory reservation system
- Notification system (email & events)

---

# Project Goals

This project was built to simulate real-world backend engineering challenges in e-commerce systems, including:

- Scalable backend architecture
- Secure authentication flows
- Complex cart synchronization
- Inventory consistency and tracking
- Maintainable and modular design

---

# Final Notes

This system is intentionally designed with a balance between simplicity and structure.

It avoids over-engineering while still applying solid architectural separation where business complexity requires it.

The goal is to remain:

- Maintainable
- Scalable
- Production-oriented

---

# License

This project is proprietary and confidential. Unauthorized copying, distribution, or modification is strictly prohibited.
