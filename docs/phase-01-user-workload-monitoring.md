# Fase 1 — Habilitar o User Workload Monitoring no OpenShift 4.20

## Objetivo

Habilitar e validar o **User Workload Monitoring (UWM)** no OpenShift Container Platform 4.20 para permitir, nas fases seguintes, a coleta de métricas das aplicações de usuário por meio do stack de monitoramento nativo da plataforma.

Nesta fase não serão instalados componentes adicionais de observabilidade, como OpenTelemetry ou Tempo.

---

## 1. Pré-requisitos

- OpenShift Container Platform 4.20 provisionado e operacional.
- Acesso ao cluster com usuário que possua a role `cluster-admin`.
- OpenShift CLI (`oc`) instalada e autenticada no cluster.

Valide o acesso:

```bash
oc whoami
oc get clusterversion
```

---

## 2. Verificar se o User Workload Monitoring já está habilitado

O User Workload Monitoring é habilitado por meio do ConfigMap:

```text
openshift-monitoring/cluster-monitoring-config
```

Verifique se ele existe:

```bash
oc get configmap cluster-monitoring-config   -n openshift-monitoring   -o yaml
```

### Cenário A — ConfigMap existente

Se o objeto existir, verifique se contém:

```yaml
data:
  config.yaml: |
    enableUserWorkload: true
```

Se `enableUserWorkload` estiver definido como `true`, avance para a seção de validação.

### Cenário B — ConfigMap inexistente

Em clusters onde nenhuma customização do monitoring foi feita anteriormente, o comando pode retornar:

```text
Error from server (NotFound): configmaps "cluster-monitoring-config" not found
```

Isso não representa uma falha do Cluster Monitoring Operator. Nesse cenário, crie o ConfigMap com a configuração mínima necessária para habilitar o User Workload Monitoring.

---

## 3. Criar o `cluster-monitoring-config`

No repositório do laboratório, crie:

```text
platform/
└── monitoring/
    └── cluster-monitoring-config.yaml
```

Conteúdo:

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: cluster-monitoring-config
  namespace: openshift-monitoring
data:
  config.yaml: |
    enableUserWorkload: true
```

Aplique:

```bash
oc apply -f platform/monitoring/cluster-monitoring-config.yaml
```

Resultado esperado:

```text
configmap/cluster-monitoring-config created
```

> Se o ConfigMap já existir e possuir outras configurações, preserve os parâmetros existentes e adicione apenas `enableUserWorkload: true` dentro de `data.config.yaml`.

A Red Hat define `enableUserWorkload` como o parâmetro booleano responsável por habilitar o monitoramento de projetos definidos pelos usuários.

---

## 4. Validar o ConfigMap

Confirme a configuração:

```bash
oc get configmap cluster-monitoring-config   -n openshift-monitoring   -o yaml
```

O trecho relevante deve ser:

```yaml
data:
  config.yaml: |
    enableUserWorkload: true
```

Também é possível verificar diretamente o conteúdo interno de `config.yaml`:

```bash
oc get configmap cluster-monitoring-config   -n openshift-monitoring   -o jsonpath='{.data.config\.yaml}'
```

Resultado esperado:

```yaml
enableUserWorkload: true
```

---

## 5. Aguardar a criação do stack de User Workload Monitoring

Após a aplicação da configuração, o Cluster Monitoring Operator provisiona os componentes de monitoramento no namespace:

```text
openshift-user-workload-monitoring
```

Acompanhe:

```bash
oc get pods -n openshift-user-workload-monitoring -w
```

Os principais componentes que devem aparecer são:

```text
prometheus-operator
prometheus-user-workload
thanos-ruler-user-workload
```

Exemplo de estado saudável observado no laboratório:

```text
NAME                                    READY   STATUS    RESTARTS   AGE
prometheus-operator-xxxxxxxxxx-xxxxx    2/2     Running   0          ...
prometheus-user-workload-0              6/6     Running   0          ...
thanos-ruler-user-workload-0            4/4     Running   0          ...
```

Os números de containers podem variar conforme a release e a configuração do cluster. O critério principal é que os pods estejam em `Running` e seus containers estejam `Ready`.

---

## 6. Validar especificamente o Prometheus User Workload

Confirme a existência do StatefulSet:

```bash
oc get statefulset prometheus-user-workload   -n openshift-user-workload-monitoring
```

Valide os replicas:

```bash
oc get statefulset prometheus-user-workload   -n openshift-user-workload-monitoring   -o jsonpath='{.status.readyReplicas}{"\n"}'
```

O valor deve ser igual ou superior a:

```text
1
```

Também é possível acompanhar até que exista pelo menos uma réplica pronta:

```bash
until [ "$(oc get statefulset prometheus-user-workload   -n openshift-user-workload-monitoring   -o jsonpath='{.status.readyReplicas}' 2>/dev/null)" -ge 1 ] 2>/dev/null; do
  sleep 10
done
```

---

## 7. Verificar o `user-workload-monitoring-config`

Quando o User Workload Monitoring é habilitado, o OpenShift cria por padrão o ConfigMap:

```text
openshift-user-workload-monitoring/user-workload-monitoring-config
```

Verifique:

```bash
oc get configmap user-workload-monitoring-config   -n openshift-user-workload-monitoring   -o yaml
```

Neste laboratório, não é necessário customizar esse ConfigMap nesta fase.

Ele será utilizado apenas se houver necessidade posterior de configurar parâmetros específicos do stack de UWM, como recursos, retenção ou comportamento dos componentes suportados pela API do Cluster Monitoring Operator.

---

## 8. Criar o namespace da aplicação

Prepare o namespace que será utilizado nas próximas fases:

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: php-observability-demo
```

Sugestão de arquivo:

```text
applications/
└── php-demo/
    └── openshift/
        └── namespace.yaml
```

Aplicação:

```bash
oc apply -f applications/php-demo/openshift/namespace.yaml
```

Validação:

```bash
oc get namespace php-observability-demo
```

---

## 9. Topologia ao final da Fase 1

```text
                     OpenShift 4.20
                           |
                           v
                Cluster Monitoring Operator
                           |
                           | enableUserWorkload: true
                           v
          openshift-user-workload-monitoring
                           |
          +----------------+----------------+
          |                |                |
          v                v                v
 Prometheus Operator   Prometheus UWM   Thanos Ruler
                           |
                           v
               Métricas de workloads
               das próximas fases
```

O monitoring da plataforma continua separado do monitoring das aplicações:

```text
openshift-monitoring
    |
    +-- monitoramento dos componentes da plataforma

openshift-user-workload-monitoring
    |
    +-- monitoramento dos workloads dos usuários
```

---

## 10. Estrutura do repositório após a Fase 1

```text
ocp-php-observability-lab/
|
├── README.md
├── docs/
│   └── phase-01-user-workload-monitoring.md
|
├── platform/
│   └── monitoring/
│       └── cluster-monitoring-config.yaml
|
└── applications/
    └── php-demo/
        └── openshift/
            └── namespace.yaml
```

---

## 11. Critérios de conclusão

A Fase 1 pode ser considerada concluída quando:

- `cluster-monitoring-config` existe em `openshift-monitoring`;
- `data.config.yaml` contém `enableUserWorkload: true`;
- o namespace `openshift-user-workload-monitoring` está operacional;
- `prometheus-operator` está em `Running`;
- `prometheus-user-workload` possui ao menos uma réplica pronta;
- `thanos-ruler-user-workload` está em `Running`;
- `user-workload-monitoring-config` existe;
- o namespace `php-observability-demo` foi criado;
- os manifests utilizados estão versionados no repositório.

---

## 12. Referências oficiais Red Hat

- **Configuring user workload monitoring — OpenShift Container Platform 4.20**  
  https://docs.redhat.com/en/documentation/monitoring_stack_for_red_hat_openshift/4.20/html-single/configuring_user_workload_monitoring/

- **Config map reference for the Cluster Monitoring Operator — OpenShift Container Platform 4.20**  
  https://docs.redhat.com/en/documentation/monitoring_stack_for_red_hat_openshift/4.20/html/config_map_reference_for_the_cluster_monitoring_operator/config-map-reference-for-the-cluster-monitoring-operator

A documentação oficial estabelece que `enableUserWorkload: true` no `cluster-monitoring-config`, localizado em `openshift-monitoring`, habilita o monitoramento de projetos definidos pelos usuários. Ela também indica como validação a execução dos componentes `prometheus-operator`, `prometheus-user-workload` e `thanos-ruler-user-workload` no projeto `openshift-user-workload-monitoring`.
