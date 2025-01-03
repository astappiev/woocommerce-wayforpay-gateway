# Модуль WayForPay для Wordpress WooCommerce

## Встановлення

1.  Вміст архіву помістити в папку плагінів Wordpress (за замовчуванням `{wproot}/wp-content/plugins/`)
2.  Зайти в адмін розділ сайту (`/wp-admin/`) та активувати плагін "WooCommerce WayForPay Payments"
3.  Перейти до розділу `WooCommerce -> Settings -> Checkout`
4.  Внизу сторінки в пункті `WayForPay – Internet acquiring`, натиснути кнопку `Settings` біля `Card payments, Apple Pay and Google Pay.`
5.  Ввести дані вашого мерчанта.

У полі **Merchant Login** необхідно вставити `MERCHANT LOGIN`.  
У полі **Merchant Secret key** – вставте, будь ласка, `MERCHANT SECRET KEY`.

![Settings](https://github.com/user-attachments/assets/7c74f1c4-31b9-4331-98f2-29b157ba2bc0)

## Відмінності від [оригінального плагіну](https://github.com/wayforpay/Word-Press-Woocommerce)

1. Встановлено мінімальну версію PHP 7.2, WordPress 6.0 та WooCommerce 7.6
2. Переписано та відформатовано код, щоб він був читабельним та відповідав стандартам Wordpress
3. Виправлено завантаження локалізації, відповідно до змін у WordPress 6.7
4. Задекларовано сумісність з WooCommerce HPOS (Hight-Performance Order System)
