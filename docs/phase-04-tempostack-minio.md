# Fase 4 — TempoStack com MinIO

## Objetivo

Persistir os traces da aplicação PHP Slim em um `TempoStack`, mantendo o Red Hat build of OpenTelemetry Collector como ponto central de recepção e encaminhamento. O backend S3-compatible será MinIO.

## Arquitetura

```text
PHP Slim
  |
  | OTLP HTTP/protobuf :4318
  v
RHBO OpenTelemetry Collector
  |
  | OTLP gRPC + Bearer Token + X-Scope-OrgID=dev
  v
TempoStack gateway
  |
  v
Tempo
  |
  v
MinIO / bucket tempo
```

## Componentes

- Tempo Operator: pacote `tempo-product`, canal `stable`, catálogo `redhat-operators`.
- TempoStack: namespace `tempo`, nome `lab`.
- Tenant: `dev`.
- MinIO: namespace `minio`, Service `minio:9000`, bucket `tempo`.
- Collector: namespace `observability`, ServiceAccount `otel-collector`.

## 4.1 Instalar o Tempo Operator

```bash
./scripts/01-install-tempo-operator.sh
```

Valide:

```bash
oc get csv -n openshift-tempo-operator
oc get pods -n openshift-tempo-operator
oc get crd tempostacks.tempo.grafana.com
```

O CSV deve ficar em `Succeeded`.

## 4.2 Implantar MinIO

```bash
./scripts/02-deploy-minio.sh
```

O script gera credenciais e cria dois Secrets: `minio-root-credentials` em `minio` e `tempo-minio` em `tempo`. As credenciais não ficam versionadas.

Valide:

```bash
oc get pod,svc,pvc -n minio
```

A console MinIO é opcional:

```bash
oc apply -f platform/minio/40-console-route.yaml
oc get route minio-console -n minio
```

## 4.3 Criar o bucket do Tempo

```bash
./scripts/03-create-minio-bucket.sh
```

Valide o log do Job e confirme o bucket `tempo`.

## 4.4 Criar o TempoStack

```bash
./scripts/04-deploy-tempostack.sh
```

O CR usa `tenants.mode: openshift`, gateway e RBAC habilitados. Isso deixa a stack preparada para integração posterior com o Cluster Observability Operator.

Valide:

```bash
oc get tempostack lab -n tempo
oc get tempostack lab -n tempo -o yaml
oc get pods -n tempo
oc get svc -n tempo
```

Procure `Ready=True` em `status.conditions`.

## 4.5 Integrar Collector → Tempo

```bash
./scripts/05-configure-collector-tempo.sh
```

O Collector passa a usar:

```yaml
otlp/tempo:
  endpoint: tempo-lab-gateway.tempo.svc.cluster.local:8090
  tls:
    insecure: false
    ca_file: /var/run/secrets/kubernetes.io/serviceaccount/service-ca.crt
  auth:
    authenticator: bearertokenauth
  headers:
    X-Scope-OrgID: dev
```

O `debug` exporter é mantido em paralelo nesta fase para facilitar a validação.

## 4.6 Gerar e validar traces

```bash
./scripts/06-generate-traffic.sh
./scripts/07-validate-tempo.sh
```

Também acompanhe:

```bash
oc logs -n observability deployment/otel-collector -f
```

Não devem existir erros recorrentes de autenticação, TLS ou exportação para o gateway do Tempo.

## RBAC de leitura

O manifesto `platform/tempo/20-rbac-read.yaml` concede leitura do tenant `dev` ao grupo `system:authenticated`. Isso é conveniente para o laboratório, mas é amplo demais para produção.

## Observação sobre MinIO

A Red Hat lista MinIO como backend suportado para o object storage do TempoStack. O MinIO, porém, não é produto Red Hat. O Deployment deste pacote é propositalmente simples: uma instância, um PVC e uso da credencial administrativa pelo Tempo. Não use esse desenho como referência de produção.

## Referências

- Red Hat OpenShift distributed tracing platform 3.10 — Installing distributed tracing: https://docs.redhat.com/en/documentation/red_hat_openshift_distributed_tracing_platform/3.10/html/installing_distributed_tracing/distr-tracing-tempo-installing
- Red Hat OpenShift distributed tracing platform 3.10 — Configuring distributed tracing: https://docs.redhat.com/en/documentation/red_hat_openshift_distributed_tracing_platform/3.10/html/configuring_distributed_tracing/
- OpenShift Container Platform 4.20 — Red Hat build of OpenTelemetry: https://docs.redhat.com/en/documentation/openshift_container_platform/4.20/html-single/red_hat_build_of_opentelemetry/red_hat_build_of_opentelemetry
- MinIO upstream Kubernetes documentation: https://min.io/docs/minio/kubernetes/upstream/
