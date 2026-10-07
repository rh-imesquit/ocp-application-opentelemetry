# Fase 5 — Cluster Observability Operator

## 1. Objetivo

Instalar o Red Hat OpenShift Cluster Observability Operator (COO) e habilitar o
plugin de Distributed Tracing na console do OpenShift.

O COO não substitui o Tempo e não é responsável por armazenar traces. Nesta
arquitetura:

```text
PHP Slim
   |
OpenTelemetry PHP
   |
RHBO OpenTelemetry Collector
   |
TempoStack
   |
Cluster Observability Operator
   |
Observe -> Traces
```

O Tempo continua sendo o backend de tracing. O COO adiciona a integração de UI
e a experiência de visualização dentro da console do OpenShift.

## 2. Pré-requisitos

Antes desta fase, valide:

```bash
oc get tempostack lab -n tempo
oc get pods -n tempo
```

O `TempoStack` deve ser multi-tenant. Neste laboratório ele foi configurado com:

```yaml
tenants:
  mode: openshift
```

## 3. Estrutura de arquivos

```text
operators/
└── cluster-observability/
    ├── 00-namespace.yaml
    ├── 10-operatorgroup.yaml
    └── 20-subscription.yaml

platform/
└── cluster-observability/
    └── 10-ui-plugin-distributed-tracing.yaml

scripts/
├── 01-install-cluster-observability-operator.sh
├── 02-enable-distributed-tracing-ui.sh
├── 03-validate-phase-05.sh
└── deploy-phase-05.sh
```

## 4. Instalar o Cluster Observability Operator

A instalação usa:

- namespace: `openshift-cluster-observability-operator`
- package: `cluster-observability-operator`
- channel: `stable`
- source: `redhat-operators`
- approval: `Automatic`
- installation mode: all namespaces

Execute:

```bash
./scripts/01-install-cluster-observability-operator.sh
```

Valide:

```bash
oc get subscription -n openshift-cluster-observability-operator
oc get csv -n openshift-cluster-observability-operator
oc get pods -n openshift-cluster-observability-operator
```

O CSV deve apresentar:

```text
PHASE
Succeeded
```

## 5. Habilitar Distributed Tracing UI

O recurso utilizado é:

```yaml
apiVersion: observability.openshift.io/v1alpha1
kind: UIPlugin
metadata:
  name: distributed-tracing
spec:
  type: DistributedTracing
```

Execute:

```bash
./scripts/02-enable-distributed-tracing-ui.sh
```

Valide:

```bash
oc get uiplugin distributed-tracing
oc get uiplugin distributed-tracing -o yaml
```

## 6. Validação da fase

Execute:

```bash
./scripts/03-validate-phase-05.sh
```

Depois acesse a console do OpenShift:

```text
Observe -> Traces
```

A interface deve permitir selecionar uma instância multi-tenant do Tempo e realizar
consultas de traces.

## 7. Separação de responsabilidades

```text
Componente                         Responsabilidade
------------------------------------------------------------------
OpenTelemetry PHP                 Geração dos spans da aplicação
RHBO OpenTelemetry Collector      Recepção/processamento/exportação
TempoStack                        Persistência e consulta dos traces
MinIO                             Object storage do Tempo
Cluster Observability Operator    Integração/experiência na console
DistributedTracing UIPlugin       Observe -> Traces
```

## 8. Observação sobre channels

Para OpenShift 4.20+, a documentação atual do COO recomenda o channel `stable`.
Nas versões mais recentes do COO também existe o channel `fast`; `stable` é
version-aware e é a escolha apropriada para este laboratório.

## 9. Referências oficiais Red Hat

- Installing Red Hat OpenShift Cluster Observability Operator:
  https://docs.redhat.com/en/documentation/red_hat_openshift_cluster_observability_operator/1-latest/html/installing_red_hat_openshift_cluster_observability_operator/

- UI plugins for Red Hat OpenShift Cluster Observability Operator:
  https://docs.redhat.com/en/documentation/red_hat_openshift_cluster_observability_operator/1-latest/html/ui_plugins_for_red_hat_openshift_cluster_observability_operator/
