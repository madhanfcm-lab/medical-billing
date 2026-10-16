<div class="form-check form-switch">
    <input type="hidden" name="is_active" value="0">
    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" @checked(old('is_active', $model->is_active))>
    <label class="form-check-label" for="is_active">Active</label>
</div>
