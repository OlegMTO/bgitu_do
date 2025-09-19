# 📘 DPO Project

## 📖 Описание

Проект представляет собой систему для взаимодействия студентов, преподавателей и администрации в рамках дополнительного профессионального образования (ДПО). Он включает веб-интерфейс, базы данных и связанный документооборот.

---

## 📂 Структура каталогов

```
DPO/
├── docs/              # Документы и инструкции
├── sql/               # SQL-скрипты для базы данных
├── src/               # Исходный код (HTML, JS, CSS)
├── forms/             # Формы и шаблоны заявок
├── reports/           # Отчеты и выгрузки
└── README.md          # Описание проекта
```

---

## 🚀 Запуск проекта

1. Установите локальный сервер (например, XAMPP или Node.js).
2. Разверните базу данных:

   ```bash
   psql -U postgres -f sql/init.sql
   ```
3. Откройте `src/index.html` в браузере.
4. Для тестирования форм используйте тестовые данные из `docs/test-data.xlsx`.

---

## 📌 Известные проблемы

* ❌ Некоторые формы не имеют валидации email и телефона.
* ❌ SQL-скрипты требуют доработки: есть конфликты с `NOT NULL` и внешними ключами.
* ❌ Навигация между страницами не всегда последовательна.

---

## 🛠 Рекомендации

* Добавить серверную валидацию всех форм.
* Проверить корректность всех связей в БД.
* Объединить UI-стили в единый CSS.
* Дополнить документацию примерами API-запросов.

---

## 🔗 Логика взаимодействия страниц

```mermaid
flowchart TD
    A[Главная] --> B[Регистрация / Вход]
    A --> C[Каталог курсов]
    A --> H[О проекте]

    B --> D[Личный кабинет]
    C --> D

    D --> E[Заявка на курс]
    D --> F[Документы]
    D --> I[Уведомления]

    F --> G[Загрузка файлов]
    G --> J[Обработка администратором]

    J --> K[Подтверждение / Отказ]
    K --> D
```

---

## 🗄 Логика базы данных

```mermaid
erDiagram
    USERS ||--o{ COURSES : записывается
    USERS ||--o{ APPLICATIONS : подает
    COURSES ||--o{ APPLICATIONS : содержит
    APPLICATIONS ||--o{ DOCUMENTS : прикрепляет
    DOCUMENTS }o--|| ADMIN : проверяет
```

---

## 🎯 Дальнейшее развитие

* 📌 Вынести бизнес-логику на сервер (например, Django / Express).
* 📌 Реализовать REST API для интеграции с внешними системами.
* 📌 Подключить систему уведомлений (Email, Telegram-бот).
* 📌 Добавить тестирование (Unit-тесты и e2e-тесты).

---

## Архитектурная диаграмма (Mermaid)

```mermaid
flowchart TD
    %% Client Layer
    Browser["Web Browser"]:::frontend

    %% Web Server Layer
    WebServer["Apache/Nginx + PHP-FPM"]:::server

    %% Application Layer
    subgraph "Application Layer"
        AuthModule["Auth & Security Module"]:::module
        CourseModule["Course & Module Module"]:::module
        QuizModule["Quiz & Exam Module"]:::module
        DocModule["Document Upload & Approval Module"]:::module
        AdminModule["Admin Dashboard Module"]:::module
        TeacherModule["Teacher Dashboard Module"]:::module
    end

    %% Data Layer
    subgraph "Data Layer"
        DB["Relational Database"]:::database
        FileStore["File Storage (/uploads)"]:::filesystem
    end

    %% External Services
    subgraph "External Services"
        EmailService["SMTP / Email Service"]:::external
    end

    %% Connections
    Browser --> WebServer
    WebServer --> AuthModule
    WebServer --> CourseModule
    WebServer --> QuizModule
    WebServer --> DocModule
    WebServer --> AdminModule
    WebServer --> TeacherModule

    AuthModule --> DB
    CourseModule --> DB
    QuizModule --> DB
    DocModule --> FileStore
    DocModule --> DB
    AdminModule --> DB
    TeacherModule --> DB

    AuthModule --> EmailService

    %% Styles
    classDef frontend fill:#AED6F1,stroke:#1F618D,color:#1F618D
    classDef server fill:#ABEBC6,stroke:#196F3D,color:#196F3D
    classDef module fill:#F9E79F,stroke:#B7950B,color:#B7950B
    classDef database fill:#F5B7B1,stroke:#CB4335,color:#CB4335
    classDef filesystem fill:#D5DBDB,stroke:#424949,color:#424949
    classDef external fill:#D7BDE2,stroke:#6C3483,color:#6C3483

```
### 🔗 Основные модули
- [login.php](./login.php)
- [register.php](./register.php)
- [courses.php](./courses.php)
- [exam.php](./exam.php)
- [admin_dashboard.php](./admin_dashboard.php)
- [teacher_dashboard.php](./teacher_dashboard.php)

---

© 2025, DPO Project
