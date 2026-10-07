# Ansible automation

Automação do laboratório OpenTelemetry/Tempo/MinIO no OpenShift.

## Estrutura

```text
ansible/
├── ansible.cfg
├── requirements.yml
├── inventory/
│   └── hosts.yml
├── group_vars/
│   └── all/
│       ├── vars.yml
│       └── vault.yml
├── playbooks/
│   └── destroy.yml
└── README.md
```

## Pré-requisitos

- `oc` autenticado no cluster correto
- Ansible instalado
- Python com dependências Kubernetes disponíveis
- Collection `kubernetes.core`

Crie o ambiente virtual (caso ainda não exista)
python3 -m venv .venv

Ative o venv
source .venv/bin/activate

Atualize o pip e instale as dependências necessárias para o Ansible/Kubernetes
pip install --upgrade pip
pip install ansible kubernetes


Instale a collection:

```bash
ansible-galaxy collection install -r ansible/requirements.yml
```

## Vault

O arquivo:

```text
ansible/group_vars/all/vault.yml
```

é fornecido com placeholders.

Edite os valores e criptografe:

```bash
ansible-vault encrypt ansible/group_vars/all/vault.yml
```

Para editar depois:

```bash
ansible-vault edit ansible/group_vars/all/vault.yml
```

## Destroy

Execute a partir da raiz do repositório:

```bash
ansible-playbook \
  -i ansible/inventory/hosts.yml \
  ansible/playbooks/destroy.yml \
  --ask-vault-pass
```

Como o destroy atual não precisa ler as credenciais do MinIO, caso o vault ainda
esteja em texto claro ou contenha apenas placeholders, também é possível executar
sem `--ask-vault-pass`.

## Observações

- O destroy remove primeiro os Custom Resources e depois os respectivos Operators.
- O namespace `openshift-monitoring` nunca é removido.
- Quando `destroy_monitoring_config: true`, somente o ConfigMap usado pelo lab é removido.
- `destroy_operators: false` preserva COO, RHBO OpenTelemetry e Tempo Operator.
- As tasks usam `state: absent`, portanto a execução é idempotente para recursos já ausentes.
