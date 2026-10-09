# 0.9.0-beta.2

Version préliminaire pour le développement interne, l'assurance qualité et les
tests sandbox/staging. Corrige des problèmes détectés lors des tests de bout en
bout de `0.9.0-beta.1` en sandbox ; plusieurs concernent la sécurité, la mise à
jour est donc fortement recommandée.

**Mise à jour depuis 0.9.0-beta.1 :** le nom technique du plugin est passé de
`KommandhubFlutterwaveSW` à `KmhFlutterwaveSW`, Shopware le considère donc comme
un nouveau plugin. Désinstallez l'ancien plugin, installez et activez
`KmhFlutterwaveSW`, puis saisissez à nouveau les paramètres du plugin (clés API,
hash secret du webhook). Le moyen de paiement et les commandes existantes sont
conservés ; les coordonnées bancaires enregistrées par les clients sous beta.1
ne sont pas reprises et doivent être saisies à nouveau.

- Sécurité : un paiement n'est désormais accepté que pour la commande pour laquelle il a été créé. Auparavant, un paiement réussi antérieur du même montant pouvait être réutilisé pour marquer une autre commande comme payée.
- Sécurité : les webhooks `refund.completed` confirment désormais le statut du remboursement auprès de Flutterwave au lieu de se fier au contenu du webhook.
- Sécurité : le paiement est bloqué avec un message clair lorsque la clé API ne correspond pas au mode (clé de test en mode live, ou clé live en mode sandbox).
- Sécurité : la vérification du compte bancaire est limitée (10 vérifications par client et par heure), et seul le compte vérifié par Flutterwave peut être enregistré, avec le nom renvoyé par Flutterwave.
- Corrigé : après 3-D Secure, les clients arrivaient sur une page d'erreur alors que leur paiement avait réussi. Flutterwave redirige désormais vers une URL de retour dédiée et infalsifiable.
- Corrigé : chaque webhook `refund.completed` échouait, si bien que les remboursements n'étaient jamais finalisés dans Shopware.
- Corrigé : le contrôle contre le sur-remboursement ne voyait pas les remboursements précédents ; il lit désormais l'historique complet des remboursements Flutterwave.
- Corrigé : les remboursements que Flutterwave règle immédiatement sont finalisés tout de suite au lieu d'attendre un webhook.
- Corrigé : un hash secret de webhook configuré pour un seul canal de vente est désormais accepté.
- Corrigé : le formulaire de vérification du compte bancaire dans l'espace client ne se chargeait pas.
- Corrigé : des clés de traduction s'affichaient telles quelles dans le formulaire de coordonnées bancaires et lors du paiement.
- Corrigé (Administration) : d'autres remboursements sont possibles après un remboursement partiel ; la fenêtre de remboursement se ferme après succès ; un remboursement ne peut plus être déclenché deux fois ; le statut de la transaction est mis à jour immédiatement après un remboursement.
- Les appels à Flutterwave expirent désormais après 30 secondes au lieu de bloquer le paiement.
- Supporte Shopware 6.6 et 6.7.

# 0.9.0-beta.1

Version préliminaire pour le développement interne, l'assurance qualité et les
tests sandbox/staging. Pas encore soumise au Shopware Store. L'API publique et
les espaces de noms peuvent encore changer avant la version `1.0.0`.

- Paiement Flutterwave pour Shopware 6 : carte, virement bancaire et mobile money.
- La vérification du paiement contrôle le statut, le montant et la devise avant qu'une commande ne soit marquée comme payée.
- Remboursements depuis la page de détail de la commande, y compris les remboursements partiels, avec historique des remboursements en temps réel et protection côté serveur contre le sur-remboursement.
- Autorisation d'administration dédiée "Remboursement Flutterwave" pouvant être attribuée à des rôles (dépend de l'autorisation d'édition des commandes).
- Gestion des webhooks pour `charge.completed` et `refund.completed`, avec vérification de signature et traitement idempotent, résistant aux doublons.
- Vérification du compte bancaire dans le compte client (résolution de compte via Flutterwave), avec un champ BVN optionnel.
- Les montants sont transmis à Flutterwave dans l'unité principale de la devise, comme son API l'exige, et comparés avec précision selon les décimales propres à chaque devise — y compris les devises à zéro et trois décimales (par ex. RWF, UGX, KWD). Le plugin ne crée pas de devises ni de langues dans la boutique.
- Interface du plugin traduite en anglais, allemand et français.
- Journalisation configurable (par canal de vente), mode sandbox/live et montant minimum de remboursement.
- Supporte Shopware 6.6 et 6.7.
