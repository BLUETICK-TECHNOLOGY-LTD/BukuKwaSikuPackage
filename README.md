# 📦 Bukukwasiku Laravel Package

A Laravel package that provides pre-built migrations, configuration, and API routes for Bukukwasiku features.

---

## ⚙️ Installation

```bash
composer require your-vendor/bukukwasiku
```

---

## 🚀 Setup After Installation

After installing the package, run the following commands to **publish configuration** and **migrations**, and then **migrate the database**.

### 1️⃣ Publish Configuration File

```bash
php artisan vendor:publish --tag=bukukwasiku-config
```

This will copy the config file to:

```
config/bukukwasiku.php
```

---

### 2️⃣ Publish Migrations

```bash
php artisan vendor:publish --tag=bukukwasiku-migrations
```

This will copy the package migrations into your project’s:

```
database/migrations/
```

---

### 3️⃣ Run Database Migrations

```bash
php artisan migrate
```

This will apply all the Bukukwasiku tables to your database.

---

### 4️⃣ (Optional) Clear and Cache Config

After publishing, refresh your configuration cache:

```bash
php artisan config:clear
php artisan config:cache
```

---

## 🧩 Routes

The package automatically registers its API routes under the `/api` prefix.  
You can find them in:

```
/vendor/your-vendor/bukukwasiku/Presentation/Http/Routes/api.php
```

---

## 🧠 Notes

- The package **automatically loads migrations** and **registers routes** under the `api` middleware group.
- Configuration and migration files are **safe to edit after publishing**.

---

✅ **That’s it!**  
Your Bukukwasiku package is ready to use. 🎉
