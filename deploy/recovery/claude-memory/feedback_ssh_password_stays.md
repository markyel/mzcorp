---
name: ssh-password-stays
description: "На новом сервере mzCorp вход по SSH-паролю не отключать, ключи только в дополнение"
metadata:
  node_type: memory
  type: feedback
  originSessionId: <скрыто>
  modified: 2026-11-04T16:19:09.615Z
---

Вход по SSH-паролю на новом сервере не отключать: ключ добавляется только в дополнение к паролю.

**Why:** пользователю нужен доступ к серверу с других компьютеров, где ключа нет.

**How to apply:** не трогать `PasswordAuthentication` при настройке и усилении защиты сервера. Защищаться длинным паролем и fail2ban с коротким баном. См. [[beget-db-loss-recovery]].
