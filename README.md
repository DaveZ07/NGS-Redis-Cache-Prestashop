# NGS Redis Cache for PrestaShop 8.x

![Redis](https://upload.wikimedia.org/wikipedia/en/thumb/6/6b/Redis_Logo.svg/200px-Redis_Logo.svg.png)

**NGS Redis Cache** is an advanced caching module for PrestaShop 8.x and later, replacing standard caching mechanisms (File System, Memcached) with a high-performance and scalable solution based on **Redis**. The module uses the `predis` library (PHP version) for direct communication with the Redis server, eliminating the need to install the `php-redis` PHP extension on some hosting environments (although it is recommended for maximum performance).

## Why use this module?

Standard PrestaShop cache based on the file system often becomes a bottleneck with a large number of products or high traffic due to disk I/O limitations. NGS Redis Cache offloads this burden to RAM, offering:

*   **Lightning-fast data access:** RAM operations are orders of magnitude faster than disk operations.
*   **Reduced database load:** Efficient caching of SQL queries and PrestaShop objects.
*   **Stability:** Special architecture preventing recursion loops (a common problem when integrating cache with `Configuration::get`).
*   **Granular control:** Ability to disable cache for critical store elements (Cart, Orders, API).

---

## Key Features

### 1. Advanced Cache Management
*   **Full Core Integration:** Overrides `Cache` and `CacheRedis` classes, integrating deeply with the PrestaShop core.
*   **Unix Socket Support:** Ability to connect via socket (faster than TCP/IP) or standard Host/Port.
*   **Authorization:** Full support for Redis AUTH (password).
*   **Database Separation:** Ability to select `Database ID` (0-15), allowing multiple stores to be cached on a single Redis instance.

### 2. Smart Exclusions (Blacklisting)
Not everything should be cached. The module offers powerful exclusion tools:
*   **Table Blacklist:** Define database tables that should never be cached (e.g., logs, sessions, carts).
*   **Controller Blacklist:** Disable cache for dynamic pages (e.g., `OrderController`, `CartController`, `MyAccount`).
*   **Feature Toggles:** Quickly disable cache for:
    *   Checkout Page
    *   Webservice API (for external ERP integrations)
    *   Product Listing (if using dynamic filters)

### 3. Monitoring and Statistics
Built-in dashboard in the configuration panel shows in real-time:
*   **Hit Rate:** Ratio of hits to misses (key efficiency indicator).
*   **Memory Usage:** Current and peak RAM usage by Redis.
*   **Key Count:** Total number of objects in the database.
*   **Uptime:** Redis server uptime.
*   **Connected Clients:** Number of active connections.

### 4. Automation and Security
*   **Cron Job:** Dedicated, token-protected URL for periodic cache clearing (e.g., at night).
*   **Health Check:** API endpoint for monitoring Redis service availability by external systems (e.g., UptimeRobot, Zabbix).
*   **Recursion Protection:** Connection configuration is stored in a separate PHP file (`config/redis.php`) rather than in the database, preventing crashes during PrestaShop initialization.

---

## Installation

1.  **Download:** Download the `.zip` package with the latest version of the module.
2.  **Upload:** In the admin panel, go to **Improve > Modules > Module Manager** and click "Upload a module".
3.  **Install:** After uploading, click "Install".
4.  **Verify Overrides:** The module installs files in the `override/` directory. Ensure that the `var/cache/prod/class_index.php` (or `dev`) file has been deleted so that PrestaShop detects the new classes.

> **Note:** If you already have other modules modifying the `Cache` class, a conflict may occur. Manual verification of files in `override/classes/cache/` is recommended.

---

## Configuration

Go to **Advanced Parameters > NGS Redis Cache**.

### Section: Connection
*   **Unix Socket Path:** If your Redis server and Web server are on the same machine, provide the socket path (e.g., `/var/run/redis/redis.sock`). This is the fastest method.
*   **Redis Host / Port:** If not using a socket, provide IP (usually `127.0.0.1`) and port (`6379`).
*   **Redis Password:** If your Redis requires authorization.
*   **Redis Database ID:** Default `0`. Change if another application is running on the same Redis server.
*   **Cache Key Prefix:** Default `ngs_`. Important to avoid key collisions.

### Section: Exclusions (Tuning)
*   **Blacklisted Tables:** Enter table names (without database prefix), e.g.:
    ```
    cart
    customer_session
    connections
    ```
*   **Blacklisted Controllers:** Enter controller names (`controller` parameter from URL), e.g.:
    ```
    order
    orderopc
    auth
    ```

---

## For Developers

### File Structure
*   `classes/` - Module business logic and Redis client wrapper.
*   `controllers/` - Admin and front controllers (Cron/HealthCheck).
*   `override/` - Key files overriding PrestaShop core (`Cache`, `CacheRedis`).
*   `vendor/` - External libraries (Composer), including `predis/predis`.

### Used Hooks
The module listens for object change events to automatically clear related cache entries (Invalidation):
*   `actionObjectAddAfter`, `actionObjectUpdateAfter`, `actionObjectDeleteAfter` - Clear cache after editing any object (Product, Category, etc.).
*   `actionObjectImage...` - Update cache after image changes.
*   `actionClearCompileCache` - Full Redis flush when clearing Smarty cache.

### API Endpoints
*   **Cron Clear:** `index.php?fc=module&module=ngs_redis&controller=cron&token=XXX&type=clear`
*   **Health Check:** `index.php?fc=module&module=ngs_redis&controller=healthCheck&token=XXX`

---

## Troubleshooting

**Problem: "Redis cache not active" in Cron logs.**
*   Solution: Ensure that `config/redis.php` contains correct data and the Redis server is running. Check the "Performance" tab in PrestaShop to see if the cache system is enabled.

**Problem: Store loads, but product changes are not visible.**
*   Solution: Check if module hooks are correctly attached in "Module Positions". Try clearing the cache manually using the button in the module configuration.

**Problem: 500 Error after installation.**
*   Solution: Often results from override conflicts. Check PHP server logs. Delete the `var/cache/prod/class_index.php` file.

---

## License
Module available under the **Academic Free License (AFL 3.0)**.
Copyright © 2024 NGS.
