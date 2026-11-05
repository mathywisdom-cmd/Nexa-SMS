import type { SupabaseClient } from 'npm:@supabase/supabase-js@2.119.0'
import { ProvisioningError, removeIdentity } from './http.ts'

export async function cancelProvisioningRequest(
  service: SupabaseClient,
  requestId: string,
  operatorAuthUserId: string | null,
) {
  const { data: cancellation, error: beginError } = await service.rpc('begin_provisioning_cancellation', {
    p_request_id: requestId,
    p_operator_auth_user_id: operatorAuthUserId,
  })
  if (beginError || !cancellation) throw new ProvisioningError('request_not_cancellable', true)

  const authUserId = cancellation.auth_user_id as string | null
  let deletionConfirmed = !authUserId
  if (authUserId) {
    const { data: authResult, error: lookupError } = await service.auth.admin.getUserById(authUserId)
    const authUser = authResult?.user
    const isRequestIdentity = authUser?.user_metadata?.nexasms_provisioning_request_id === requestId
    if (!authUser && lookupError?.status === 404) {
      deletionConfirmed = true
    } else if (lookupError) {
      deletionConfirmed = false
    } else if (isRequestIdentity) {
      try {
        await removeIdentity(service, authUserId)
        deletionConfirmed = true
      } catch {
        // The request can time out after Auth deletes the identity. Verify the
        // actual Auth state before recording compensation as successful.
        const { data: afterDelete, error: verifyError } = await service.auth.admin.getUserById(authUserId)
        deletionConfirmed = !afterDelete?.user && verifyError?.status === 404
      }
    }
  }

  const { data: finished, error: finishError } = await service.rpc('finish_provisioning_cancellation', {
    p_request_id: requestId,
    p_operator_auth_user_id: operatorAuthUserId,
    p_succeeded: deletionConfirmed,
  })
  if (finishError || !finished) throw new ProvisioningError('cancellation_recovery_required')
  return { request_id: requestId, state: finished.state, cancelled: deletionConfirmed }
}
