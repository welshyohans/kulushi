# Android prompt: Referral Campaign (Invite & Reward)

## Goal
Add a new screen where an **existing customer** can participate in an invite campaign, select a preferred reward tier, register friends, and see progress. A referral is **counted** only when the referred customer has **at least 1 delivered order** (`deliver_status = 6`).

The backend APIs for this are in:
- `api/referralCampaign/tiers.php`
- `api/referralCampaign/register.php`
- `api/referralCampaign/addReferral.php`
- `api/referralCampaign/status.php`
- `api/referralCampaign/claimReward.php`

## Eligibility rules (backend enforced)
- Registrar (existing customer) must have `customer.segment` in: `active`, `loyal`, `vip`.
- Existing customer can only be added as a referral if their `customer.segment` is `potential` or `new`.
- Phone numbers are normalized server-side (supports `09xxxxxxxx`, `9xxxxxxxx`, and `+251...` formats).
- A referred customer can only belong to **one** registrar in this campaign.

## Screen 1: “Join Referral Campaign”

### UI
- Header: “Invite Campaign”
- Reward selector (4 options from backend tiers)
- CTA: “Join Campaign”
- If already joined, skip selector and go directly to Progress screen

### API integration
1) Load tiers:
- `GET /api/referralCampaign/tiers.php`

Response:
```json
{ "success": true, "tiers": [ { "tierId": 1, "requiredReferrals": 1, "rewardName": "Tecno T302" } ] }
```

2) Join campaign (save preferred reward tier):
- `POST /api/referralCampaign/register.php`

Request JSON parameters:
```json
{ "customerId": 123, "tierId": 1 }
```

Notes:
- If the customer is not eligible (segment not active), backend returns `403`.
- If the customer already joined, backend returns `200` with `alreadyRegistered=true`.

## Screen 2: “Campaign Progress”

### UI
- Show selected reward and goal (required referrals)
- Progress: qualified delivered referrals / goal
- List of referrals:
  - referred customer name/shop/phone
  - status chips: “Registered”, “Ordered”, “Delivered (Counted)”
- Button: “Invite / Add Friend”
- If goal reached: show “Claim Reward” button

### API integration
1) Load progress and referral list:
- `POST /api/referralCampaign/status.php`

Request JSON parameters:
```json
{ "customerId": 123 }
```

Important response fields:
- `participant.tier.requiredReferrals`
- `progress.qualifiedDelivered`
- `progress.remainingToGoal`
- `progress.canClaimReward`
- `referrals[]` items include `hasOrdered`, `hasDelivered`, `firstOrderTime`, `firstDeliveredTime`

2) Claim reward (only if `canClaimReward=true`):
- `POST /api/referralCampaign/claimReward.php`

Request JSON parameters:
```json
{ "customerId": 123 }
```

Response behavior:
- `201` when claim is created (`status="requested"`)
- `200` when claim already exists (`alreadyRequested=true`)
- `409` when not enough delivered referrals yet

## “Invite / Add Friend” flow
You can reuse the existing “Register your friend” UI, but call the new campaign API because it supports:
- adding an **existing** customer by phone (only if segment is `potential` or `new`)
- creating a **new** customer if phone does not exist
- automatically linking the referred customer to the registrar’s campaign progress

### API
- `POST /api/referralCampaign/addReferral.php`

Request JSON parameters:
```json
{
  "registrarCustomerId": 123,
  "phone": "0912345678",
  "name": "Friend Name",
  "shopName": "Friend Shop",
  "addressId": 2
}
```

Notes:
- `name`, `shopName`, `addressId` are required only when the phone is **not** already registered in the customer table.
- On successful creation of a new customer, backend sends an SMS password to the friend.

## Error handling expectations (Android)
- `400`: missing migration/table/column or invalid payload
- `403`: not eligible by segment, or referred customer segment not allowed
- `404`: customer/tier/address not found
- `409`: already joined, already referred (duplicate), not joined yet, or cannot claim yet
- `500`: server/database errors

