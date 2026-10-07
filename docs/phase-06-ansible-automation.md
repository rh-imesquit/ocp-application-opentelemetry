# Fase 06 — Automação do laboratório com Ansible

## 1. Objetivo

A Fase 06 tem como objetivo automatizar o ciclo de vida completo do laboratório de observabilidade no OpenShift utilizando Ansible.

Ao final desta fase, o repositório passa a possuir duas operações principais:

- provisionar o laboratório;
- destruir o laboratório.

A automação utiliza os manifests já existentes no repositório, evitando duplicação de configuração entre YAML do OpenShift e Ansible.

A estrutura lógica passa a ser:

```text
apps/
  -> aplicação PHP e seus manifests

infra/
  -> infraestrutura declarativa do laboratório

ansible/
  -> orquestração da instalação e remoção dos recursos
```

## 2. Escopo da automação

O Ansible será responsável por controlar a ordem de criação, validação e remoção dos seguintes componentes:

```text
User Workload Monitoring
        ↓
Red Hat build of OpenTelemetry Operator
        ↓
Tempo Operator
        ↓
Cluster Observability Operator
        ↓
MinIO
        ↓
TempoStack
        ↓
OpenTelemetry Collector
        ↓
Aplicação PHP Slim
        ↓
Distributed Tracing UIPlugin
```

O objetivo não é recriar os manifests dentro das playbooks.

Os arquivos em `apps/` e `infra/` continuam sendo a fonte declarativa dos recursos Kubernetes/OpenShift, enquanto o Ansible controla a ordem em que esses manifests são processados.

## 3. Estrutura do diretório Ansible

A estrutura utilizada no laboratório é:

```text
ansible/
├── .gitignore
├── README.md
├── ansible.cfg
├── requirements.yml
├── inventory/
│   └── hosts.yml
├── group_vars/
│   └── all/
│       ├── vars.yml
│       └── vault.yml
└── playbooks/
    ├── provision.yml
    └── destroy.yml
```

### ansible.cfg

Contém as configurações padrão utilizadas pela automação, como inventário, collections e comportamento de execução.

### requirements.yml

Declara as collections necessárias para manipular recursos Kubernetes/OpenShift.

Neste laboratório é utilizada:

```yaml
collections:
  - name: kubernetes.core
```

### inventory/hosts.yml

O Ansible é executado localmente e utiliza o contexto Kubernetes/OpenShift atual.

Exemplo:

```yaml
all:
  hosts:
    localhost:
      ansible_connection: local
      ansible_python_interpreter: "{{ ansible_playbook_python }}"
```

Não é necessário cadastrar os nodes do OpenShift no inventário.

## 4. Variáveis

As variáveis comuns ficam em:

```text
ansible/group_vars/all/vars.yml
```

Exemplos:

```yaml
php_namespace: php-observability-demo
observability_namespace: observability
tempo_namespace: tempo
minio_namespace: minio

tempo_stack_name: lab
tempo_tenant_name: dev
tempo_bucket_name: tempo
```

Também são definidas nesse arquivo referências para os manifests existentes no repositório.

Dessa forma, a playbook não precisa conhecer caminhos hard coded em cada task.

## 5. Credenciais e Ansible Vault

Valores sensíveis não devem ser armazenados diretamente em arquivos comuns versionados no Git.

As credenciais do laboratório ficam em:

```text
ansible/group_vars/all/vault.yml
```

Exemplo:

```yaml
vault_minio_root_user: minioadmin
vault_minio_root_password: senha-do-laboratorio
```

O arquivo deve ser criptografado com:

```bash
ansible-vault encrypt ansible/group_vars/all/vault.yml
```

Para editar posteriormente:

```bash
ansible-vault edit ansible/group_vars/all/vault.yml
```

Durante a execução da playbook:

```bash
ansible-playbook \
  -i ansible/inventory/hosts.yml \
  ansible/playbooks/provision.yml \
  --ask-vault-pass
```

ou:

```bash
ansible-playbook \
  -i ansible/inventory/hosts.yml \
  ansible/playbooks/destroy.yml \
  --ask-vault-pass
```

## 6. Playbook de provisionamento

A playbook `provision.yml` deve criar os componentes respeitando a ordem de dependência.

Ordem recomendada:

```text
1. User Workload Monitoring

2. Operators
   ├── Red Hat build of OpenTelemetry
   ├── Tempo Operator
   └── Cluster Observability Operator

3. MinIO
   ├── Namespace
   ├── Secret
   ├── PVC
   ├── Deployment
   ├── Service
   ├── Route
   └── Bucket tempo

4. TempoStack
   ├── Namespace
   ├── Secret de acesso ao MinIO
   ├── TempoStack
   └── RBAC

5. OpenTelemetry Collector
   ├── Namespace
   ├── ServiceAccount
   ├── RBAC
   └── Collector

6. Aplicação PHP
   ├── Namespace
   ├── ImageStream
   ├── BuildConfig
   ├── Deployment
   ├── Service
   └── Route

7. Cluster Observability
   └── UIPlugin DistributedTracing
```

Entre algumas etapas é necessário aguardar que os recursos estejam disponíveis.

Exemplos:

- CSV do Operator em `Succeeded`;
- Deployment do MinIO em `Available`;
- TempoStack em `Ready=True`;
- OpenTelemetry Collector em `Available`;
- aplicação PHP em `Available`.

## 7. Playbook de destruição

A playbook `destroy.yml` executa a ordem inversa do provisionamento.

A remoção deve começar pelos recursos dependentes e terminar pelos Operators.

Ordem adotada:

```text
1. Aplicação PHP

2. UIPlugin DistributedTracing

3. OpenTelemetry Collector
   ├── Collector
   ├── ServiceAccount
   └── RBAC

4. TempoStack
   └── RBAC

5. MinIO

6. Operators
   ├── Cluster Observability Operator
   ├── Red Hat build of OpenTelemetry
   └── Tempo Operator

7. User Workload Monitoring
```

Essa ordem reduz o risco de recursos presos em `Terminating` por causa de finalizers controlados por Operators que já tenham sido removidos.

## 8. Cuidados com recursos do cluster

O laboratório não deve remover namespaces nativos do OpenShift.

Por exemplo:

```text
openshift-monitoring
```

nunca deve ser removido.

Ao destruir a configuração de User Workload Monitoring, somente o recurso criado pelo laboratório deve ser removido, como:

```text
ConfigMap cluster-monitoring-config
```

Também é possível preservar os Operators definindo:

```yaml
destroy_operators: false
```

Isso é útil quando o cluster utiliza os mesmos Operators para outros testes.

## 9. Idempotência

As playbooks devem ser executáveis mais de uma vez sem causar falhas por recursos já existentes ou já removidos.

A collection `kubernetes.core` permite utilizar operações declarativas como:

```yaml
state: present
```

e:

```yaml
state: absent
```

No destroy, por exemplo, tentar remover um recurso que já não existe não deve impedir a execução das tarefas seguintes.

## 10. Validação final da Fase 06

A fase pode ser considerada concluída quando os seguintes cenários forem validados:

```text
Provisionamento completo do laboratório      ✔
Aplicação PHP respondendo                     ✔
Traces chegando ao Collector                  ✔
Traces persistidos no Tempo                   ✔
Observe -> Traces funcionando                 ✔
Destroy completo do laboratório               ✔
Nova execução do provision sem intervenção    ✔
```

O teste mais importante é validar o ciclo completo:

```text
provision
   ↓
validação funcional
   ↓
destroy
   ↓
provision novamente
```

Se o segundo provisionamento funcionar sem ajustes manuais, a automação está reproduzível.

## 11. Resultado esperado

Ao final da Fase 06, o laboratório deixa de depender de uma sequência manual de comandos e passa a ser reproduzível por meio de Ansible.

Fluxo final:

```text
Git repository
      |
      v
Ansible
      |
      +--> apps/
      |
      +--> infra/
      |
      v
OpenShift
      |
      +--> PHP Slim
      +--> OpenTelemetry
      +--> Tempo
      +--> MinIO
      +--> COO
```

Essa abordagem preserva os manifests como fonte declarativa e utiliza o Ansible apenas como camada de orquestração.
