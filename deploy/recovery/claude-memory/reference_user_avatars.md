---
name: user-avatars
description: "Аватарки пользователей (3 варианта neutral/won/lost): загрузка админом, показ в списке заявок, карточке и виджете дашборда"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Аватарки пользователя — 3 варианта: **neutral** / **won** / **lost**. Задаёт privileged (head_of_sales/director/admin).

- **Хранение**: 3 nullable-колонки `users.avatar_{neutral,won,lost}_path` (относительный путь). Диск `User::AVATAR_DISK = 'local'` (private), файлы в `storage/app/private/avatars/{user_id}/{variant}-{rand}.{ext}`.
- **User-хелперы**: `avatarPath($variant)`, `hasAvatar()`, `avatarUrl($variant)` (= `route('user.avatar',...).'?v='.hash(path)` — версия сбрасывает браузерный кэш при замене), `avatarUrlForStatus(?RequestStatus)` (won→won-аватар если есть, lost→lost, иначе neutral). Константы `AVATAR_DISK`, `AVATAR_VARIANTS`.
- **Отдача**: `UserAvatarController@show(User,$variant)` с диска local, `Cache-Control public max-age=30д`. Роут `user.avatar` = `/avatars/{user}/{variant}` (where variant neutral|won|lost), под `auth`.
- **Загрузка**: `App\Livewire\Admin\Managers\Avatars` (WithFileUploads, image mimes png/jpg/webp ≤1МБ), встроен в `admin/managers/edit.blade.php` под mailbox-oauth. Заменяет старый файл при загрузке.
- **Показ** (аватар вместо кружка инициалов при наличии):
  - список заявок `pool.blade.php` — `$req->assignedUser?->avatarUrl('neutral')` (22px). Eager-load в `Pool::render` расширен: `assignedUser:id,name,avatar_neutral_path,avatar_won_path,avatar_lost_path`.
  - карточка `detail.blade.php` — `avatarUrlForStatus($req->status)` (44px, inline-size). Eager-load в `Detail` (load + reloadRequest) тоже расширен на 3 avatar-колонки.
  - дашборд-виджет «Менеджеры · текущая загрузка/период» — `avatar_url` добавлен в `Dashboard\Index::managersCurrentLoad` и `managersAssignedInPeriod` (30px).
- **Грабля**: partial eager-select `assignedUser:id,name` молча вернёт null у avatar-колонок — при добавлении показа в новом месте НЕ забыть включить колонки в select.
- Размеры/`object-fit` заданы inline-стилем (без новых Tailwind-классов → без `npm run build`). Роут новый → нужен `route:cache` на деплое.
