# MultiFlexi Semaphore

MultiFlexi credential prototype for [Semaphore UI](https://semaphoreui.com/) REST API.

Provides the `Semaphore` credential prototype with two fields:

| Keyword           | Type     | Description                                             |
|-------------------|----------|---------------------------------------------------------|
| `SEMAPHORE_URL`   | url      | Semaphore UI base URL (without `/api`), e.g. `https://semaphore.proxy.spojenet.cz` |
| `SEMAPHORE_TOKEN` | password | Semaphore API token (Bearer), from Semaphore UI → user → API keys |

## Usage

This package also ships the `MultiFlexi\Action\Semaphore` action (installed into
`/usr/share/php/MultiFlexi/Action/`, auto-discovered by MultiFlexi). It uses a
credential of this prototype to trigger a Semaphore task:

```
POST {SEMAPHORE_URL}/api/project/{project_id}/tasks
Authorization: Bearer {SEMAPHORE_TOKEN}
{"template_id": <id>, "environment": "{...json string...}"}
```

Configure the action on a RunTemplate in MultiFlexi (`actions.php`): select the Semaphore
credential, project ID and template ID. When the job finishes (mode `success`), the
Semaphore template is launched with the job environment forwarded (`EVENT_*` variables
from event rules are always included).

## Installation

```shell
sudo apt install lsb-release wget
echo "deb http://repo.vitexsoftware.com $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/vitexsoftware.list
sudo wget -O /etc/apt/trusted.gpg.d/vitexsoftware.gpg http://repo.vitexsoftware.cz/keyring.gpg
sudo apt update
sudo apt install multiflexi-semaphore multiflexi-semaphore-ui
```

The postinst runs `multiflexi-cli credential-prototype:sync`, which registers the
prototype in the MultiFlexi database.
